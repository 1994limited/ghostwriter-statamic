<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImage;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as Entries;
use Statamic\Support\Str;

/**
 * Makes the images a draft needs. Nothing about a site's look is assumed:
 * for each image field, the pictures already in that field on the entries the
 * draft is modelled on are handed to the image model as references, so an
 * article gets a picture like the other articles and a page whose images are
 * a logo on a flat colour gets that.
 */
class ImageStudio
{
    private const REFERENCES = 3;

    /** Photographs offered for a field, and results considered per search. */
    private const SHORTLIST = 3;

    private const PER_TERM = 6;

    private const RASTER = ['jpg', 'jpeg', 'png', 'webp'];

    /** Entries looked through for references when none were picked. */
    private const SAMPLE = 12;

    public function __construct(private SchemaReader $reader, private StockSearch $stock, private ImageryGuide $guide, private PromptLibrary $prompts, private Providers $providers, private Studio $studio) {}

    /**
     * The provider to make images with: the one chosen in settings, or the
     * first that has an API key. Null when there is none.
     */
    public function provider(): ?string
    {
        return $this->providers->imageHandle();
    }

    public function configured(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * The image fields of the draft that a picture can be made for: those at
     * the top of the entry, and those on its blocks that the entries it is
     * modelled on fill in.
     *
     * @return array<string, array{key: string, label: string, container: string, multiple: bool, references: array<int, Asset>}>
     */
    public function slots(Session $session, ContentType $type): array
    {
        $blueprint = $type->statamicBlueprint();

        if ($session->draft === null || ! $blueprint) {
            return [];
        }

        try {
            $draft = Draft::parse($session->draft)->data;
        } catch (InvalidArgumentException) {
            return [];
        }

        $schema = $this->reader->read($blueprint);
        $models = $this->models($type);
        $slots = [];

        foreach ($schema as $spec) {
            // An image field nobody fills in, such as one the site generates
            // itself, is not offered. With no entries yet, there is nothing
            // to go by, so every top-level image field is.
            if ($this->isImageField($spec) && (($references = $this->references($models, $spec)) !== [] || $models === [])) {
                $slots[$spec['handle']] = $this->slot($spec['handle'], $spec['display'] ?: $spec['handle'], $spec, $references);
            }

            if ($spec['kind'] !== 'blocks') {
                continue;
            }

            $seen = [];

            foreach ((array) ($draft[$spec['handle']] ?? []) as $block) {
                $set = is_array($block) ? ($block['type'] ?? null) : null;

                if (! is_string($set) || ! isset($spec['sets'][$set])) {
                    continue;
                }

                $n = $seen[$set] = ($seen[$set] ?? -1) + 1;

                foreach ($spec['sets'][$set]['fields'] as $field) {
                    $references = $this->isImageField($field) ? $this->references($models, $field, $spec['handle'], $set) : [];

                    // A block image nobody fills in is not part of the page.
                    if ($references === []) {
                        continue;
                    }

                    $key = implode(':', [$spec['handle'], $set, $n, $field['handle']]);
                    $label = $spec['sets'][$set]['display'].': '.($field['display'] ?: $field['handle']);

                    $slots[$key] = $this->slot($key, $label, $field, $references);
                }
            }
        }

        return $slots;
    }

    /**
     * A spread of the images one collection uses, for the style guide to be
     * written from: a few from each image field, newest entries first.
     *
     * @return array<int, array{label: string, entry: string, image: Image}>
     */
    public function samples(string $collection, int $limit = 10): array
    {
        $entries = Entries::query()->where('collection', $collection)->where('published', true)->get()
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::SAMPLE * 2);

        $byField = [];

        foreach ($entries as $entry) {
            foreach ($this->reader->read($entry->blueprint()) as $spec) {
                $fields = $this->isImageField($spec) ? [[$spec, $spec['display'] ?: $spec['handle'], [$entry->get($spec['handle'])]]] : [];

                foreach ($spec['kind'] === 'blocks' ? ($spec['sets'] ?? []) : [] as $set => $config) {
                    foreach (array_filter($config['fields'], fn (array $field) => $this->isImageField($field)) as $field) {
                        $fields[] = [$field, $config['display'].': '.($field['display'] ?: $field['handle']), collect((array) $entry->get($spec['handle']))->where('type', $set)->pluck($field['handle'])->all()];
                    }
                }

                foreach ($fields as [$field, $label, $values]) {
                    foreach ($values as $value) {
                        $path = is_array($value) ? ($value[0] ?? null) : $value;
                        $asset = is_string($path) && $path !== '' ? AssetContainer::find($field['container'])?->asset($path) : null;

                        if ($asset && in_array(strtolower((string) $asset->extension()), self::RASTER, true)) {
                            $byField[$label][$asset->id()] ??= ['label' => $label, 'entry' => (string) $entry->get('title'), 'asset' => $asset];
                        }
                    }
                }
            }
        }

        // Turn about between the fields, so one busy field does not crowd out the rest.
        $queues = array_map('array_values', array_values($byField));
        $picked = [];

        for ($i = 0; count($picked) < $limit && $i < $limit; $i++) {
            foreach ($queues as $queue) {
                if (isset($queue[$i]) && count($picked) < $limit && ! isset($picked[$queue[$i]['asset']->id()])) {
                    $picked[$queue[$i]['asset']->id()] = $queue[$i];
                }
            }
        }

        return collect($picked)
            ->map(fn (array $sample) => ['label' => $sample['label'], 'entry' => $sample['entry'], 'image' => $this->small((string) $sample['asset']->contents())])
            ->filter(fn (array $sample) => $sample['image'] !== null)
            ->values()
            ->all();
    }

    /**
     * What the writer is told about images: the fields it can arrange them
     * for and what it can do about each, given the tools this site has.
     */
    public function describe(ContentType $type): string
    {
        $blueprint = $type->statamicBlueprint();
        $canFind = $this->stock->sources() !== [];
        $canMake = $this->configured();

        if (! $blueprint || (! $canFind && ! $canMake)) {
            return 'This site has no image tools switched on. Leave image fields out of the draft; a person adds images afterwards. If asked for images, say so plainly.';
        }

        $models = $this->models($type);
        $fields = [];

        foreach ($this->reader->read($blueprint) as $spec) {
            if ($this->isImageField($spec) && ($this->references($models, $spec) !== [] || $models === [])) {
                $fields[] = "- `{$spec['handle']}`: ".($spec['display'] ?: $spec['handle']);
            }

            foreach ($spec['kind'] === 'blocks' ? ($spec['sets'] ?? []) : [] as $set => $config) {
                foreach ($config['fields'] as $field) {
                    if ($this->isImageField($field) && $this->references($models, $field, $spec['handle'], $set) !== []) {
                        $fields[] = "- `{$spec['handle']}:{$set}:0:{$field['handle']}`: {$config['display']}, ".($field['display'] ?: $field['handle']).". The number counts that block type in the draft from 0, so the second `{$set}` block is 1.";
                    }
                }
            }
        }

        if ($fields === []) {
            return 'Entries of this kind carry no images that are chosen per entry. Leave image fields out of the draft.';
        }

        $actions = array_filter([
            $canFind ? '- `find`: search free photo libraries and show your colleague photographs to choose from. Do this for every image field whenever you write a first draft, without being asked, and again if the subject of the entry changes.' : null,
            $canFind ? '- `fill`: search and put the best match straight into the field. Use this when your colleague asks you to add, choose, pick or fill in images.' : null,
            $canMake ? '- `make`: have a new image made, in the style of the images this site already uses in that field. Use this only when your colleague asks for an image to be made or generated.' : null,
        ]);

        return "Never put an image field in the draft. You arrange images with an `<images>` block instead, placed after the draft, holding one line per image field in the form `field | action | words`.\n\n"
            ."Image fields:\n".implode("\n", $fields)."\n\n"
            ."Actions:\n".implode("\n", $actions)."\n\n"
            .'Words: for `find` and `fill`, three different searches separated by semicolons, each two to four plain words naming something concrete that can be photographed: objects, places, scenes. Make them three different angles, not three wordings of one. No brand names, no abstract ideas. The photographs that best match the images this site already uses are then chosen from the results.'
            ."\n\nChoosing what to search for is the part that matters. When the entry is about a thing (a project, a product, a place), search for that thing as the site's other images would show it. When it is about an idea, do not search for the activity it literally describes, which returns stock clichés; name a concrete, good-looking object or scene that stands for the argument the entry makes. An entry arguing that a careful repair can beat a replacement is pottery mended with gold, not builders on scaffolding. Whatever you choose must look at home beside the site's other images."
            .(($style = $this->guide->for((string) $type->statamicCollection()?->title())) !== '' ? "\n\nThe house style for images in this section of the site:\n\n{$style}" : '')
            .($canMake ? ' For `make`, one sentence describing the image.' : '')
            ."\n\nInclude a line only for an image that should change, and leave the block out otherwise. Say in your reply what you did about images, in a few words. Images are part of your job here: never say you cannot help with them."
            .($canMake ? '' : ' This site cannot make new images, only find photographs; if asked to generate one, say so and offer to find one.')
            .' A logo or brand mark is never found or made: if that is what an image should be, tell your colleague to use "Logo card" under Images, which needs the logo file from them.';
    }

    /**
     * Do what the writer asked for in its `<images>` block.
     */
    public function carryOut(Session $session, ContentType $type, string $block): Session
    {
        $slots = $this->slots($session, $type);

        foreach (preg_split('/\R/', trim($block)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', trim($line, " \t-`")));

            if (count($parts) < 3 || ! isset($slots[$key = trim($parts[0], '`')])) {
                continue;
            }

            [$action, $words] = [strtolower($parts[1]), trim(implode(' ', array_slice($parts, 2)))];

            if ($words === '') {
                continue;
            }

            try {
                $session->images[$key] = match (true) {
                    $action === 'make' && $this->configured() => $this->startMaking($session, $key, $words),
                    $action === 'fill' => $this->fill($session, $type, $key, $words),
                    default => $this->offer($session, $type, $key, $words),
                };
            } catch (\Throwable $exception) {
                report($exception);

                $session->images[$key] = ['status' => 'failed', 'error' => $exception->getMessage()] + ($session->images[$key] ?? []);
            }
        }

        return $session;
    }

    /**
     * Photographs for the person to choose from. What is already in the
     * field stays until they pick one.
     *
     * @return array<string, mixed>
     */
    private function offer(Session $session, ContentType $type, string $key, string $words): array
    {
        $current = $session->images[$key] ?? [];

        if (($current['query'] ?? null) === $words && ! empty($current['options'])) {
            return $current;
        }

        return ['query' => $words, 'options' => $this->shortlist($session, $type, $key, $words)] + $current + ['status' => 'empty'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fill(Session $session, ContentType $type, string $key, string $words): array
    {
        $options = $this->shortlist($session, $type, $key, $words);
        $best = $options[0] ?? throw new InvalidArgumentException("No photograph was found for \"{$words}\".");
        $photo = $this->stock->fetch($best['source'], $best['id']);

        $asset = $this->keep($session, $type, $key, $photo['content'], $photo['extension'], [
            'credit' => $photo['credit'],
            'credit_url' => $photo['credit_url'],
            'licence' => $photo['licence'],
        ]);

        // The rest stay on offer, in case the first is not the one.
        return ['status' => 'done', 'path' => $asset->path(), 'url' => $asset->url(), 'error' => null, 'credit' => $photo['credit'], 'query' => $words, 'options' => array_slice($options, 1)];
    }

    /**
     * Photographs worth offering: each search term is run, and the three
     * results that sit best beside the images the field already holds come
     * first, followed by the rest.
     *
     * @param  string  $words  One or more searches, separated by semicolons.
     * @return array<int, array<string, mixed>>
     */
    public function shortlist(Session $session, ContentType $type, string $key, string $words): array
    {
        $terms = array_slice(array_values(array_filter(array_map('trim', explode(';', $words)))), 0, self::SHORTLIST);
        $shape = $this->shapeFor($session, $type, $key);
        $references = ($this->slots($session, $type)[$key] ?? [])['references'] ?? [];
        $style = $this->guide->for((string) $type->statamicCollection()?->title());

        $candidates = $this->candidates($terms, $shape);
        $retry = null;
        $best = $this->judged($candidates, $references, $session->title(), $style, $retry);

        // The judge found nothing that belongs and said what to look for
        // instead. One more round with its searches, then settle.
        if ($best === null && $retry) {
            $second = $this->candidates($retry, $shape);
            $best = $this->judged($second, $references, $session->title(), $style);
            $candidates = [...$second, ...$candidates];

            if ($best === null && $second !== []) {
                $best = $this->oneOfEach($second);
            }
        }

        $best ??= $this->oneOfEach($candidates);
        $ids = array_map(fn (array $photo) => $photo['source'].$photo['id'], $best);

        // The pick comes first; the rest follow for anyone who wants to look further.
        return [...$best, ...array_values(array_filter($candidates, fn (array $photo) => ! in_array($photo['source'].$photo['id'], $ids, true)))];
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $terms, string $shape): array
    {
        $candidates = [];

        foreach ($terms as $term) {
            foreach (array_slice($this->stock->search($term, $shape), 0, self::PER_TERM) as $photo) {
                $candidates[$photo['source'].$photo['id']] ??= $photo + ['term' => $term];
            }
        }

        return array_values($candidates);
    }

    /**
     * Ask the model, which can see both, which candidates match the site's
     * own images. Null when there is nothing to compare with or no model.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, Asset>  $references
     * @param  array<int, string>|null  $retry  Set to the searches the judge would try instead, when nothing fits.
     * @return array<int, array<string, mixed>>|null
     */
    private function judged(array $candidates, array $references, string $title, string $style = '', ?array &$retry = null): ?array
    {
        $canRetry = func_num_args() > 4;

        if (count($candidates) <= self::SHORTLIST || $references === [] || ! $this->studio->configured()) {
            return null;
        }

        try {
            $shown = collect($references)->take(self::REFERENCES)->map(fn (Asset $asset) => $this->small((string) $asset->contents()))->filter()->values();
            $thumbs = Http::pool(fn ($pool) => array_map(fn (array $photo) => $pool->timeout(15)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($photo['thumb']), $candidates));

            $seen = [];
            $attachments = $shown->all();

            foreach ($thumbs as $i => $response) {
                // Only as many as one request can carry. Three references and
                // three searches of six fit; large originals (no Imagick) may not.
                if ($response instanceof Response && $response->successful() && ($image = $this->small($response->body())) && Limits::fits([...$attachments, $image])) {
                    $attachments[] = $image;
                    $seen[] = $candidates[$i];
                }
            }

            if ($shown->isEmpty() || count($seen) <= self::SHORTLIST) {
                return null;
            }

            $list = collect($seen)->map(fn (array $photo, int $i) => ($i + 1).'. from the search "'.$photo['term'].'"')->implode("\n");

            $answer = $this->studio->ask(
                'photo-picker',
                "The page is titled \"{$title}\".\n\nThe first {$shown->count()} image(s) are the references. The ".count($seen)." after them are the candidates, in this order:\n{$list}\n\n"
                .($style !== '' ? "The site's own description of its images in this section:\n{$style}\n\n" : '')
                .'Rank the best '.(self::SHORTLIST * 2).'.'
                .($canRetry ? ' If none of them would belong beside the references, reply instead with the word none, a colon, and three better searches separated by semicolons, each two to four plain words: for example `none: mended pottery gold; restored classic car; old stone bridge`.' : ''),
                images: $attachments,
                timeout: 60,
            )->text;

            if ($canRetry && preg_match('/^\s*none\b[:\s]*(.*)$/isu', $answer, $none)) {
                $retry = array_slice(array_values(array_filter(array_map(fn (string $term) => trim($term, " \t\n\r`.\"'"), explode(';', $none[1])))), 0, self::SHORTLIST) ?: null;

                return null;
            }

            preg_match_all('/\d+/', $answer, $m);

            $ranked = collect($m[0])->map(fn (string $n) => $seen[(int) $n - 1] ?? null)->filter()->unique(fn (array $photo) => $photo['source'].$photo['id'])->values();

            // The best from each search first, so the three on offer differ;
            // then whatever ranked next.
            $chosen = $ranked->unique('term')->concat($ranked)->unique(fn (array $photo) => $photo['source'].$photo['id'])->take(self::SHORTLIST)->values()->all();

            return $chosen ?: null;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Without a judge, the top result of each search is as fair as any.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function oneOfEach(array $candidates): array
    {
        $byTerm = collect($candidates)->groupBy('term');
        $picked = collect();

        for ($i = 0; $picked->count() < self::SHORTLIST && $i < self::PER_TERM; $i++) {
            foreach ($byTerm as $photos) {
                if ($photos->has($i) && $picked->count() < self::SHORTLIST) {
                    $picked->push($photos[$i]);
                }
            }
        }

        return $picked->all();
    }

    /**
     * An image small enough to show a model many of at once.
     */
    private function small(string $content): ?Image
    {
        if ($content === '' || @getimagesizefromstring($content) === false) {
            return null;
        }

        if (! extension_loaded('imagick')) {
            return strlen($content) < 1_000_000 ? Image::fromString($content) : null;
        }

        $image = new \Imagick;
        $image->readImageBlob($content);
        $image->thumbnailImage(512, 512, true);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(75);

        return new Image($image->getImageBlob(), 'image/jpeg');
    }

    /**
     * @return array<string, mixed>
     */
    private function startMaking(Session $session, string $key, string $direction): array
    {
        GenerateImage::start($session->id, $key, $direction);

        return ['status' => 'working', 'error' => null, 'direction' => $direction] + ($session->images[$key] ?? []);
    }

    /**
     * Make one image and save it as an asset beside its references.
     *
     * @param  string|null  $source  Path to an image the editor supplied, such as a logo, to be used in the picture.
     */
    public function generate(Session $session, ContentType $type, string $key, string $direction = '', ?string $source = null): Asset
    {
        $slot = $this->slots($session, $type)[$key]
            ?? throw new InvalidArgumentException('That image field is no longer part of the draft.');

        $summary = $session->draft && preg_match('/^(?:summary|excerpt|description|intro):\s*(.+)$/mu', $session->draft, $m) ? trim($m[1], " \t\"'") : '';
        $image = $this->make($slot['references'], $session->title(), $summary, $slot['label'], $direction, $source, $this->guide->for((string) $type->statamicCollection()?->title()));

        return $this->store($session, $slot, $image->data, $image->extension());
    }

    /**
     * Have a picture made in the style of the references: the pictures
     * already in that place on the site's other entries.
     *
     * @param  array<int, Asset>  $references
     * @param  string|null  $source  Path to an image the editor supplied, such as a logo, to be used in the picture.
     */
    public function make(array $references, string $title, string $summary, string $label, string $direction = '', ?string $source = null, string $style = ''): Image
    {
        $provider = $this->providers->image()
            ?? throw new InvalidArgumentException('No image provider has an API key. Set OPENAI_API_KEY or GEMINI_API_KEY.');

        $references = collect($references)->take(self::REFERENCES);

        $attachments = $references
            ->map(fn (Asset $asset) => new Image((string) $asset->contents(), (string) $asset->mimeType()))
            ->when($source, fn ($all) => $all->push(Image::fromPath($source)))
            ->values()
            ->all();

        return $provider->image(new ImageRequest(
            $this->prompt($title, $summary, $label, $direction, $references->count(), $source !== null, $style),
            $attachments,
            $this->shape($references->first()),
        ));
    }

    /**
     * Photographs for a field on a form, chosen the same way as for a draft:
     * each search run, and the best beside the references first. Public so
     * the image button on a field can use it without a session.
     *
     * @param  array<int, string>  $terms
     * @param  array<int, Asset>  $references
     * @return array<int, array<string, mixed>>
     */
    public function shortlistFor(array $terms, array $references, string $shape, string $title, string $style = ''): array
    {
        $candidates = $this->candidates(array_slice($terms, 0, self::SHORTLIST), $shape);
        $retry = null;
        $best = $this->judged($candidates, $references, $title, $style, $retry);

        if ($best === null && $retry) {
            $second = $this->candidates($retry, $shape);
            $best = $this->judged($second, $references, $title, $style);
            $candidates = [...$second, ...$candidates];

            if ($best === null && $second !== []) {
                $best = $this->oneOfEach($second);
            }
        }

        $best ??= $this->oneOfEach($candidates);
        $ids = array_map(fn (array $photo) => $photo['source'].$photo['id'], $best);

        return [
            ...array_map(fn (array $photo) => $photo + ['picked' => true], $best),
            ...array_values(array_filter($candidates, fn (array $photo) => ! in_array($photo['source'].$photo['id'], $ids, true))),
        ];
    }

    /**
     * landscape, portrait or square, going by a picture already there.
     */
    public function shapeOf(?Asset $reference): string
    {
        return $this->shape($reference);
    }

    /**
     * An image small enough to show a model many of at once. Public for the
     * search-term chooser, which looks at the references too.
     */
    public function thumbnail(string $content): ?Image
    {
        return $this->small($content);
    }

    /**
     * The shape of image a field wants, going by what it already holds.
     */
    public function shapeFor(Session $session, ContentType $type, string $key): string
    {
        $slot = $this->slots($session, $type)[$key] ?? null;

        return $this->shape($slot['references'][0] ?? null);
    }

    /**
     * The pixel size of image a field holds, going by what it already has.
     *
     * @return array{0: int, 1: int}
     */
    public function sizeFor(Session $session, ContentType $type, string $key): array
    {
        $reference = ($this->slots($session, $type)[$key] ?? [])['references'][0] ?? null;

        $width = (int) $reference?->width();
        $height = (int) $reference?->height();

        return $width && $height ? [min($width, 2400), (int) round($height * min($width, 2400) / $width)] : [1600, 1200];
    }

    /**
     * Save a photograph found elsewhere as the image for a field.
     *
     * @param  array<string, mixed>  $meta  Credit and licence, kept on the asset.
     */
    public function keep(Session $session, ContentType $type, string $key, string $content, string $extension, array $meta = []): Asset
    {
        $slot = $this->slots($session, $type)[$key]
            ?? throw new InvalidArgumentException('That image field is no longer part of the draft.');

        return $this->store($session, $slot, $content, $extension, $meta);
    }

    /**
     * Images are filed beside the ones the field already uses.
     *
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $meta
     */
    private function store(Session $session, array $slot, string $content, string $extension, array $meta = []): Asset
    {
        $container = AssetContainer::find($slot['container'])
            ?? throw new InvalidArgumentException("The asset container \"{$slot['container']}\" no longer exists.");

        $reference = $slot['references'][0] ?? null;
        $folder = $reference ? trim(dirname($reference->path()), './') : 'ghostwriter';
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $path = ltrim($folder.'/'.(Str::slug($session->title()) ?: 'image').'-'.Str::lower(Str::random(6)).'.'.$extension, '/');

        $container->disk()->put($path, $content);

        $asset = $container->makeAsset($path);
        $asset->data(array_filter($meta))->save();

        return $asset;
    }

    /**
     * Put the session's finished images into entry data built from its draft.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function place(array $data, Session $session, array $schema): array
    {
        $specs = collect($schema)->keyBy('handle');

        foreach ($session->images as $key => $image) {
            if (($image['status'] ?? null) !== 'done' || empty($image['path'])) {
                continue;
            }

            $parts = explode(':', (string) $key);

            if (count($parts) === 1 && $specs->has($key)) {
                $data[$key] = $this->value($specs[$key], $image['path']);

                continue;
            }

            if (count($parts) !== 4) {
                continue;
            }

            [$field, $set, $n, $handle] = $parts;
            $seen = -1;

            foreach ((array) ($data[$field] ?? []) as $i => $block) {
                if (($block['type'] ?? null) === $set && ++$seen === (int) $n) {
                    $spec = collect($specs[$field]['sets'][$set]['fields'] ?? [])->firstWhere('handle', $handle);
                    $data[$field][$i][$handle] = $this->value($spec ?? [], $image['path']);
                }
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function isImageField(array $spec): bool
    {
        return ($spec['type'] ?? null) === 'assets' && ! empty($spec['container']);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, Asset>  $references
     * @return array{key: string, label: string, container: string, multiple: bool, references: array<int, Asset>}
     */
    private function slot(string $key, string $label, array $spec, array $references): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'container' => $spec['container'],
            'multiple' => ($spec['max_files'] ?? null) !== 1,
            'references' => $references,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return string|array<int, string>
     */
    private function value(array $spec, string $path): string|array
    {
        return ($spec['max_files'] ?? null) === 1 ? $path : [$path];
    }

    /**
     * The entries whose pictures set the style: the ones the piece is
     * modelled on first, then the collection's newest. The picked entries
     * may have no pictures of their own (drafts often do not), and the rest
     * of the collection still shows what belongs in each field.
     *
     * @return array<int, Entry>
     */
    private function models(ContentType $type): array
    {
        $picked = collect($type->examples)->map(fn (string $id) => Entries::find($id))->filter()->values();

        return $picked->concat($this->newest($type))->unique(fn (Entry $entry) => $entry->id())->values()->all();
    }

    /**
     * @return Collection<int, Entry>
     */
    private function newest(ContentType $type): Collection
    {
        return Entries::query()
            ->where('collection', $type->collection)
            ->where('published', true)
            ->get()
            ->when($type->blueprint, fn ($all) => $all->filter(fn (Entry $entry) => $entry->blueprint()?->handle() === $type->blueprint))
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::SAMPLE)
            ->values();
    }

    /**
     * The pictures already in this field on the model entries.
     *
     * @param  array<int, Entry>  $models
     * @param  array<string, mixed>  $spec
     * @return array<int, Asset>
     */
    private function references(array $models, array $spec, ?string $blocksField = null, ?string $set = null): array
    {
        $container = AssetContainer::find($spec['container']);
        $found = [];

        foreach ($models as $entry) {
            $values = $blocksField === null
                ? [$entry->get($spec['handle'])]
                : collect((array) $entry->get($blocksField))->where('type', $set)->pluck($spec['handle'])->all();

            foreach ($values as $value) {
                $path = is_array($value) ? ($value[0] ?? null) : $value;
                $asset = is_string($path) && $path !== '' ? $container?->asset($path) : null;

                if ($asset && in_array(strtolower((string) $asset->extension()), self::RASTER, true)) {
                    $found[$asset->id()] = $asset;
                }
            }
        }

        return array_values($found);
    }

    private function shape(?Asset $reference): string
    {
        $width = (int) $reference?->width();
        $height = (int) $reference?->height();

        if (! $width || ! $height) {
            return 'landscape';
        }

        $ratio = $width / $height;

        return $ratio > 1.2 ? 'landscape' : ($ratio < 0.83 ? 'portrait' : 'square');
    }

    private function prompt(string $title, string $summary, string $label, string $direction, int $references, bool $hasSource, string $style = ''): string
    {
        return strtr($this->prompts->get('image'), [
            '{{ field }}' => $label,
            '{{ title }}' => $title,
            '{{ summary }}' => $summary !== '' ? $summary : '(none)',
            '{{ direction }}' => trim($direction) !== '' ? trim($direction) : '(none given; choose a subject that suits the title)',
            '{{ style }}' => $style !== '' ? $style : 'No written guide; go by the reference images.',
            '{{ references }}' => match (true) {
                $references === 0 => 'No reference images are attached, so there is no house style to match. Make a clean, simple image with no text in it.',
                default => "The first {$references} attached image(s) are what this same field holds on other pages of the site. They are the house style. Match them closely: the kind of image (photograph, illustration, or a mark on a flat ground), composition, palette, lighting, texture and how much detail there is. If they contain no text, neither does yours. Make a new image that belongs in the same set; do not copy their subject.",
            },
            '{{ source }}' => $hasSource
                ? 'The last attached image was supplied by the editor, for example a logo or a product shot. It is the subject. Reproduce it exactly as it is, without redrawing, restyling or recolouring it, placed the way the references place theirs.'
                : 'No source image was supplied. Do not draw any real company\'s logo or brand mark from memory.',
        ]);
    }
}

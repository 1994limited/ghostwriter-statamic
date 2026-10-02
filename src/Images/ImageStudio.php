<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoResults;
use NineteenNinetyFour\Ghostwriter\Core\Images\Shrinker;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateImage;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
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

    private const RASTER = ['jpg', 'jpeg', 'png', 'webp'];

    /** Entries looked through for references when none were picked. */
    private const SAMPLE = 12;

    private Shrinker $shrinker;

    public function __construct(private SchemaReader $reader, private PhotoFinder $finder, private GuideStore $guides, private PromptLibrary $prompts, private Providers $providers)
    {
        $this->shrinker = new Shrinker;
    }

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
        $blueprint = TypeRepository::blueprintOf($type);

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
            ->map(fn (array $sample) => ['label' => $sample['label'], 'entry' => $sample['entry'], 'image' => $this->shrinker->small((string) $sample['asset']->contents())])
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
        $blueprint = TypeRepository::blueprintOf($type);
        $canFind = $this->finder->canFind();
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
            .(($style = $this->style($type)) !== '' ? "\n\nThe house style for images in this section of the site:\n\n{$style}" : '')
            .($canMake ? ' For `make`, one sentence describing the image.' : '')
            ."\n\nInclude a line only for an image that should change, and leave the block out otherwise. Say in your reply what you did about images, in a few words. Images are part of your job here: never say you cannot help with them."
            .($canMake ? '' : ' This site cannot make new images, only find photographs; if asked to generate one, say so and offer to find one.')
            .' A logo or brand mark is never found or made: if that is what an image should be, tell your colleague to add the logo file to that field themselves.';
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

        return self::offered($this->photos($session, $type, $key, $words)) + $current + ['status' => 'empty'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fill(Session $session, ContentType $type, string $key, string $words): array
    {
        $results = $this->photos($session, $type, $key, $words);
        $best = $results->picked()[0] ?? $results->first() ?? throw new InvalidArgumentException("No photograph was found for \"{$words}\".");
        $asset = $this->keep($session, $type, $key, $this->finder->stock()->fetch($best->source, $best->id), $best->term);

        // The rest stay on offer, in case the first is not the one.
        $rest = array_values(array_filter($results->photos, fn (Photo $photo) => $photo->key() !== $best->key()));

        return ['status' => 'done', 'path' => $asset->path(), 'url' => $asset->url(), 'error' => null, 'credit' => $best->credit]
            + self::offered(new PhotoResults($rest, $results->terms, $results->judged, $results->noneFit, $results->retried, $results->withReferences));
    }

    /**
     * Photographs for one of the draft's image fields, found and judged by
     * core: against the draft's words, and the pictures already in that
     * place on the site's other entries when there are any.
     *
     * @param  string|null  $words  Searches, separated by semicolons; null to have them chosen from the draft.
     */
    public function photos(Session $session, ContentType $type, string $key, ?string $words = null): PhotoResults
    {
        $slot = $this->slots($session, $type)[$key]
            ?? throw new InvalidArgumentException('That image field is no longer part of the draft.');

        $references = collect($slot['references'])->take(self::REFERENCES)->map(fn (Asset $asset) => (string) $asset->contents())->filter()->values()->all();

        return $this->finder->find($this->context($session, $type, $slot), $references, $words !== null && trim($words) !== '' ? $words : null);
    }

    /**
     * Search results as a field in the session keeps them, and as the panel reads them.
     *
     * @return array{query: string, options: array<int, array<string, mixed>>, judged: bool, none_fit: bool, with_references: bool}
     */
    public static function offered(PhotoResults $results): array
    {
        return [
            'query' => implode('; ', $results->terms),
            'options' => array_map(fn (Photo $photo) => $photo->toArray(), $results->photos),
            'judged' => $results->judged,
            'none_fit' => $results->noneFit,
            'with_references' => $results->withReferences,
        ];
    }

    /**
     * Where in the draft a picture goes, in words: the block it sits in, the
     * rest of the draft, and the shape of the pictures already there.
     *
     * @param  array{key: string, label: string, container: string, multiple: bool, references: array<int, Asset>}  $slot
     */
    private function context(Session $session, ContentType $type, array $slot): PhotoContext
    {
        try {
            $draft = $session->draft !== null ? Draft::parse($session->draft)->data : [];
        } catch (InvalidArgumentException) {
            $draft = [];
        }

        $block = '';
        $parts = explode(':', $slot['key']);

        if (count($parts) === 4) {
            [$field, $set, $n] = $parts;
            $block = self::words(collect((array) ($draft[$field] ?? []))->filter(fn ($item) => is_array($item) && ($item['type'] ?? null) === $set)->values()->get((int) $n, []));
        }

        $summary = is_string($found = $draft['summary'] ?? $draft['excerpt'] ?? $draft['description'] ?? $draft['intro'] ?? null) ? $found : '';

        return PhotoContext::make(
            title: $session->title(),
            label: $slot['label'],
            blockText: mb_substr($block, 0, 6000),
            pageText: mb_substr(self::words($draft), 0, 6000),
            summary: $summary,
            shape: $this->shape($slot['references'][0] ?? null),
            style: $this->style($type),
        );
    }

    /**
     * The writing in part of a draft, however it is nested.
     */
    private static function words(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        return collect($value)
            ->reject(fn ($item, $key) => in_array($key, ['id', 'type', 'enabled'], true))
            ->map(fn ($item) => self::words($item))
            ->filter()
            ->implode("\n");
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
        $image = $this->make($slot['references'], $session->title(), $summary, $slot['label'], $direction, $source, $this->style($type));

        return $this->store($session, $slot, $image->data, $image->extension());
    }

    /**
     * Have a picture made in the style of the references: the pictures
     * already in that place on the site's other entries.
     *
     * @param  array<int, Asset>  $references
     * @param  Image|string|null  $source  An image the editor supplied, such as a logo, to be used in the picture, or the path to one.
     */
    public function make(array $references, string $title, string $summary, string $label, string $direction = '', Image|string|null $source = null, string $style = ''): Image
    {
        $source = is_string($source) ? Image::fromPath($source) : $source;

        $provider = $this->providers->image()
            ?? throw new InvalidArgumentException('No image provider has an API key. Set OPENAI_API_KEY or GEMINI_API_KEY.');

        $references = collect($references)->take(self::REFERENCES);

        $attachments = $references
            ->map(fn (Asset $asset) => new Image((string) $asset->contents(), (string) $asset->mimeType()))
            ->when($source, fn ($all) => $all->push($source))
            ->values()
            ->all();

        return $provider->image(new ImageRequest(
            $this->prompt($title, $summary, $label, $direction, $references->count(), $source !== null, $style),
            $attachments,
            $this->shape($references->first()),
        ));
    }

    /**
     * landscape, portrait or square, going by a picture already there.
     */
    public function shapeOf(?Asset $reference): string
    {
        return $this->shape($reference);
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
     * Save a photograph found in a library as the image for a field, named
     * and described from what the library says it shows.
     */
    public function keep(Session $session, ContentType $type, string $key, PhotoFile $file, ?string $term = null): Asset
    {
        $slot = $this->slots($session, $type)[$key]
            ?? throw new InvalidArgumentException('That image field is no longer part of the draft.');

        $photo = $file->photo;
        $fallback = $term !== null && trim($term) !== '' ? $term : $session->title();

        return $this->store($session, $slot, $file->content, $file->extension, [
            'title' => $photo->assetTitle($fallback),
            'alt' => $photo->alt($fallback),
            'credit' => $photo->credit,
            'credit_url' => $photo->creditUrl,
            'licence' => $photo->licence,
        ], $photo->filenameBase($fallback));
    }

    /**
     * Images are filed beside the ones the field already uses.
     *
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $meta
     */
    private function store(Session $session, array $slot, string $content, string $extension, array $meta = [], ?string $name = null): Asset
    {
        $reference = $slot['references'][0] ?? null;
        $folder = $reference ? trim(dirname($reference->path()), './') : 'ghostwriter';

        return self::saveAsset($slot['container'], $folder, $name ?: (Str::slug($session->title()) ?: 'image'), $content, $extension, $meta);
    }

    /**
     * Put a picture in a container as a new asset, under a name that cannot
     * clash. Title, credit and the like are kept on the asset; alt text only
     * where the container's blueprint has an `alt` field to show it in.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function saveAsset(string $handle, string $folder, string $name, string $content, string $extension, array $meta = []): Asset
    {
        $container = AssetContainer::find($handle)
            ?? throw new InvalidArgumentException("The asset container \"{$handle}\" no longer exists.");

        $extension = $extension === 'jpeg' ? 'jpg' : (string) preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        $path = ltrim(trim($folder, '/').'/'.(Str::slug($name) ?: 'image').'-'.Str::lower(Str::random(6)).'.'.$extension, '/');

        if (! $container->blueprint()?->hasField('alt')) {
            unset($meta['alt']);
        }

        $container->disk()->put($path, $content);

        $asset = $container->makeAsset($path);
        $asset->data(array_filter($meta, fn ($value) => $value !== null && $value !== ''))->save();

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
     * What the image style guide says about the kind's collection.
     */
    public function style(ContentType $type): string
    {
        return $this->guides->guide(Guide::IMAGERY)->section((string) TypeRepository::collectionOf($type)?->title());
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
            ->where('collection', $type->group)
            ->where('published', true)
            ->get()
            ->when($type->variant, fn ($all) => $all->filter(fn (Entry $entry) => $entry->blueprint()?->handle() === $type->variant))
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

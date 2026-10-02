<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Content\ProseExtractor;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Collection as EntryCollection;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint;
use Statamic\Support\Str;
use Throwable;

/**
 * Every call the addon makes to a model goes through here: building the
 * instructions from the prompts, sending them through Ghostwriter Core's
 * providers, and turning the answer back into something the rest of the
 * addon can use.
 */
class Studio
{
    /** The most a reply that ran out of room is given on its second try. */
    private const MAX_TOKENS_CEILING = 32000;

    /**
     * Replies that are no use part-written: a draft or a guide cut off at
     * the limit must not be saved as if it were finished. The rest (lists
     * of ideas, kinds and photographs) are kept, as far as they got.
     */
    private const WHOLE = [
        'writer' => 'The draft was longer than Ghostwriter allows and was cut off. Try asking for a shorter piece.',
        'type-analyst' => 'The description of this kind of content was longer than Ghostwriter allows and was cut off. Try again.',
        'voice-analyst' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try again, or read fewer collections.',
        'voice-editor' => 'The voice guide was longer than Ghostwriter allows and was cut off. Try asking for a shorter guide.',
    ];

    /** Examples are trimmed to this many characters each. */
    private const EXAMPLE_LIMIT = 7000;

    /** Entries looked at when working out the kinds a collection holds. */
    private const KIND_SAMPLE = 60;

    public function __construct(
        private SchemaReader $reader,
        private PatternFinder $patterns,
        private SchemaDescriber $describer,
        private Settings $settings,
        private ProseExtractor $prose,
        private PromptLibrary $prompts,
        private Providers $providers,
    ) {}

    /**
     * Whether the configured provider has an API key to call with.
     */
    public function configured(): bool
    {
        return $this->providers->configured();
    }

    public function provider(): string
    {
        return $this->settings->provider();
    }

    /**
     * @param  Collection<int, array{title: string, collection: string, url: ?string, text: string}>  $samples
     */
    public function analyseVoice(Collection $samples): TaggedResponse
    {
        $prompt = "Here are {$samples->count()} samples of published writing from the website.\n\n"
            .$samples->map(fn (array $sample, int $i) => sprintf(
                "<sample number=\"%d\" collection=\"%s\" title=\"%s\">\n%s\n</sample>",
                $i + 1,
                e($sample['collection']),
                e($sample['title']),
                $sample['text'],
            ))->implode("\n\n")
            ."\n\nWrite the tone of voice guide.";

        $response = $this->ask('voice-analyst', $prompt);

        return new TaggedResponse('', trim($response->text), $response->usage->input, $response->usage->output);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        $prompt = "<current_guide>\n{$guide}\n</current_guide>\n\nRequest: {$request}";

        $response = $this->ask('voice-editor', $prompt, $history);

        return TaggedResponse::parse($response->text, 'document', $response->usage->input, $response->usage->output);
    }

    /**
     * Work out what a collection holds and what to ask before writing for it.
     *
     * @param  array<int, string>  $examples  Entry IDs to model the type on; empty to use the collection's newest.
     */
    public function analyseCollection(EntryCollection $collection, Blueprint $blueprint, ?string $title = null, array $examples = []): ContentType
    {
        $schema = $this->reader->read($blueprint);
        $pattern = $this->patterns->find($collection->handle(), $schema, $blueprint->handle(), [], $examples);

        $prompt = "Section: {$collection->title()} ({$pattern['entries']} entries studied)\n\n"
            .($title ? "The editors call this kind of content \"{$title}\". Use that as the title.\n\n" : '')
            .($examples ? "The entries below were chosen by an editor as the model for this kind of content. Other entries in the section may look different; describe only these.\n\n" : '')
            ."## The fields\n\n".$this->describer->describe($schema, $pattern)."\n\n"
            ."## Existing entries\n\n".$this->examples($pattern)."\n\n"
            .'Write the type.';

        $response = $this->ask('type-analyst', $prompt);
        [$data, $problem] = $this->readType($response->text);

        // An answer that cannot be read gets one more chance, told what was wrong.
        if ($data === null) {
            Log::warning("Ghostwriter: the type analysis for {$collection->handle()} could not be read ({$problem}):\n{$response->text}");

            $response = $this->ask(
                'type-analyst',
                "Your answer could not be read: {$problem}. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.",
                [['role' => 'user', 'content' => $prompt], ['role' => 'assistant', 'content' => $response->text]],
            );
            [$data, $problem] = $this->readType($response->text);
        }

        if ($data === null) {
            Log::warning("Ghostwriter: the type analysis for {$collection->handle()} could not be read again ({$problem}):\n{$response->text}");

            throw new InvalidArgumentException('The analysis came back in a form that could not be read. Try again.');
        }

        $handle = app(TypeRepository::class)->handleFor($title ?: '', $collection->handle());

        return ContentType::fromArray($handle, array_filter([
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->handle(),
            'examples' => $examples,
            'title' => $title,
        ]) + $data);
    }

    /**
     * The kinds of content a collection seems to hold, each with the entries
     * that show it, for a person to look over and have taught.
     *
     * @return array<int, array{title: string, description: string, why: string, examples: array<int, string>, blueprint: ?string}>
     */
    public function suggestKinds(EntryCollection $collection, TypeRepository $types, KindSuggestions $kinds): array
    {
        $state = $kinds->get($collection->handle());
        $taught = $types->forCollection($collection->handle());
        $entries = Entries::query()->where('collection', $collection->handle())->where('published', true)->get()
            ->sortByDesc(fn ($entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::KIND_SAMPLE)
            ->values();

        if ($entries->count() < 2) {
            return [];
        }

        $several = $collection->entryBlueprints()->count() > 1;
        $lines = [];

        foreach ($entries as $entry) {
            $schema = $this->reader->read($entry->blueprint());
            $builder = collect($schema)->firstWhere('kind', 'blocks');
            $built = $builder ? collect((array) $entry->get($builder['handle']))->filter(fn ($block) => is_array($block) && ($block['enabled'] ?? true) !== false)->pluck('type')->unique()->values()->all() : [];
            $opening = trim((string) preg_replace('/\s+/u', ' ', mb_substr($this->prose->fromEntry($entry), 0, 220)));

            $lines[] = sprintf(
                '- id "%s" · "%s"%s%s%s%s',
                $entry->id(),
                $entry->get('title'),
                $several ? ' · blueprint: '.$entry->blueprint()->title() : '',
                ($parent = $entry->parent()) ? ' · under: '.$parent->title() : '',
                $built ? ' · built as: '.implode(', ', $built) : '',
                $opening !== '' ? ' · opens: "'.$opening.'"' : '',
            );
        }

        $instructions = strtr($this->promptFile('kind-finder'), [
            '{{ count }}' => '5',
            '{{ taught }}' => $taught->isNotEmpty() ? $taught->map(fn (ContentType $type) => "- {$type->title}: {$type->description}")->implode("\n") : 'Nothing yet.',
            '{{ dismissed }}' => $state['dismissed'] ? '- '.implode("\n- ", $state['dismissed']) : 'Nothing yet.',
        ]);

        $response = $this->ask('kind-finder', "Section: {$collection->title()}\n\nEntries, newest first:\n".implode("\n", $lines), instructions: $instructions);
        $block = TaggedResponse::parse($response->text, 'kinds')->document;

        // No block at all is the scout saying there is nothing to add, which
        // is an answer, not a failure. What it did say is kept in the log.
        if ($block === null) {
            Log::info("Ghostwriter: no kinds suggested for {$collection->handle()}:\n{$response->text}");

            return [];
        }

        try {
            $found = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            report(new InvalidArgumentException("The kinds for {$collection->handle()} could not be read ({$exception->getMessage()}):\n{$block}"));

            throw new InvalidArgumentException('Ghostwriter did not come back with kinds it could read. Try again.');
        }

        $byId = $entries->keyBy(fn ($entry) => (string) $entry->id());
        $known = array_map('mb_strtolower', [...$taught->map(fn (ContentType $type) => $type->title)->all(), ...$state['dismissed']]);
        $out = [];

        foreach ($found as $kind) {
            if (! is_array($kind) || trim((string) ($kind['title'] ?? '')) === '' || in_array(mb_strtolower(trim((string) $kind['title'])), $known, true)) {
                continue;
            }

            // Only entries really in this collection, and at least two of them.
            $examples = array_values(array_unique(array_filter(array_map('strval', (array) ($kind['examples'] ?? [])), fn (string $id) => $byId->has($id))));

            if (count($examples) < 2) {
                continue;
            }

            $blueprints = array_unique(array_map(fn (string $id) => $byId[$id]->blueprint()->handle(), $examples));

            $out[] = [
                'title' => mb_substr(trim((string) $kind['title']), 0, 60),
                'description' => trim((string) ($kind['description'] ?? '')),
                'why' => trim((string) ($kind['why'] ?? '')),
                'examples' => array_slice($examples, 0, 6),
                'blueprint' => count($blueprints) === 1 ? reset($blueprints) : null,
            ];
        }

        return $out;
    }

    /**
     * The type the analyst wrote, or why it could not be read.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function readType(string $text): array
    {
        $yaml = TaggedResponse::parse($text, 'type')->document;

        if ($yaml === null) {
            return [null, 'there was no <type> block'];
        }

        // Models sometimes put the YAML in a code fence inside the block.
        $yaml = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($yaml));

        try {
            $data = LenientYaml::parse($yaml);
        } catch (Throwable $exception) {
            return [null, 'the YAML did not parse ('.$exception->getMessage().')'];
        }

        if (! is_array($data) || empty($data['questions']) || ! is_array($data['questions'])) {
            return [null, 'it had no questions'];
        }

        return [$data, ''];
    }

    /**
     * Ideas for entries the site is missing, from what it has and what is
     * already planned.
     *
     * @param  array<int, string>  $collections  Handles of the sections to plan for.
     * @param  array<int, array<string, mixed>>  $plan  Ideas already on the plan, whatever their status.
     * @return array<int, array{title: string, collection: string, type: ?string, why: string, notes: string}>
     */
    public function suggestIdeas(array $collections, TypeRepository $types, array $plan, string $voice, string $steer = ''): array
    {
        $sections = collect($collections)->map(function (string $handle) use ($types) {
            $collection = Collections::findByHandle($handle);

            if (! $collection) {
                return null;
            }

            $entries = Entries::query()->where('collection', $handle)->get()
                ->map(fn ($entry) => '- '.$entry->get('title').($entry->published() ? '' : ' (draft)')
                    .(is_string($summary = $entry->get('summary') ?? $entry->get('seo_description')) && $summary !== '' ? ': '.Str::limit($summary, 160) : ''))
                ->implode("\n");

            $kinds = $types->forCollection($handle)
                ->map(fn (ContentType $type) => "- `{$type->handle}`: {$type->title}. {$type->description}")
                ->implode("\n");

            return "### {$collection->title()} (`{$handle}`)\n\nKinds of content written here:\n".($kinds ?: '- none defined; leave `type` out')."\n\nEntries:\n".($entries ?: '- none yet');
        })->filter()->implode("\n\n");

        $planned = collect($plan)->map(fn (array $idea) => "- {$idea['title']} ({$idea['collection']}, {$idea['status']})")->implode("\n");

        $instructions = strtr($this->promptFile('planner'), [
            '{{ count }}' => (string) config('ghostwriter.plan.suggestions', 8),
            '{{ voice }}' => trim($voice) !== '' ? trim($voice) : 'No guide has been written yet. Judge the reader from the entries.',
            '{{ sections }}' => $sections,
            '{{ plan }}' => $planned ?: 'Nothing yet.',
        ]);

        $response = $this->ask('planner', trim($steer) !== '' ? "What I am looking for this time: {$steer}" : 'Suggest what is missing.', instructions: $instructions);
        $block = TaggedResponse::parse($response->text, 'ideas')->document
            ?? throw new InvalidArgumentException('Ghostwriter did not come back with any ideas. Try again.');

        try {
            $ideas = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            // Kept in the log, so the next failure of this kind can be read.
            report(new InvalidArgumentException("The planner's ideas could not be read ({$exception->getMessage()}):\n{$block}"));

            throw new InvalidArgumentException('Ghostwriter did not come back with ideas it could read. Try again.');
        }

        $known = collect($plan)->map(fn (array $idea) => Str::lower($idea['title']));

        return collect($ideas)
            ->filter(fn ($idea) => is_array($idea) && ! empty($idea['title']) && in_array($idea['collection'] ?? null, $collections, true))
            ->reject(fn (array $idea) => $known->contains(Str::lower((string) $idea['title'])))
            ->map(fn (array $idea) => [
                'title' => trim((string) $idea['title']),
                'collection' => (string) $idea['collection'],
                'type' => ($type = $types->find((string) ($idea['type'] ?? ''))) && $type->collection === $idea['collection'] ? $type->handle : null,
                'why' => trim((string) ($idea['why'] ?? '')),
                'notes' => trim((string) ($idea['notes'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Describe the style of one section's images from a spread of them.
     *
     * @param  array<int, array{label: string, entry: string, image: Image}>  $samples
     */
    public function analyseImagery(string $collectionTitle, array $samples): string
    {
        $list = collect($samples)->map(fn (array $sample, int $i) => ($i + 1).". {$sample['label']}, on \"{$sample['entry']}\"")->implode("\n");

        $response = $this->ask('imagery-analyst', "Section: {$collectionTitle}\n\nThe attached images, in order:\n{$list}", images: array_column($samples, 'image'));

        return (string) (TaggedResponse::parse($response->text, 'document')->document ?? trim($response->text));
    }

    /**
     * A first attempt at a type's brief from a title and some notes, for a
     * person to correct. Answers are keyed by question handle.
     *
     * @return array<string, string>
     */
    public function draftBrief(ContentType $type, string $title, string $notes = ''): array
    {
        $questions = collect($type->questions)->map(fn (array $question) => "- `{$question['handle']}`".(($question['required'] ?? false) ? ' (required)' : ' (optional)').': '.$question['label'].(empty($question['instructions']) ? '' : ' '.$question['instructions'])
            .(empty($question['options']) ? '' : ' One of: '.implode(', ', array_keys($question['options'])).'.'))->implode("\n");

        $entries = Entries::query()->where('collection', $type->collection)->get()
            ->sortByDesc(fn ($entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(40)
            ->map(fn ($entry) => '- '.$entry->get('title'))
            ->implode("\n");

        $instructions = strtr($this->promptFile('brief-writer'), [
            '{{ type_title }}' => $type->title,
            '{{ type_description }}' => $type->description,
            '{{ type_guidance }}' => $type->guidance,
            '{{ questions }}' => $questions,
            '{{ entries }}' => $entries !== '' ? $entries : 'None yet.',
        ]);

        $response = $this->ask('brief-writer', "Working title: {$title}\n\nNotes:\n".(trim($notes) !== '' ? trim($notes) : '(none)'), instructions: $instructions);
        $block = TaggedResponse::parse($response->text, 'brief')->document
            ?? throw new InvalidArgumentException('Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.');

        try {
            $answers = (array) LenientYaml::parse($block);
        } catch (Throwable) {
            throw new InvalidArgumentException('Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.');
        }

        // Only the questions that were asked, as plain text.
        return collect($type->questions)
            ->mapWithKeys(fn (array $question) => [$question['handle'] => trim(is_scalar($answers[$question['handle']] ?? null) ? (string) $answers[$question['handle']] : '')])
            ->all();
    }

    /**
     * Run the next turn of a writing session. The session's last message is
     * the colleague's latest input; on the first turn that is the brief.
     */
    public function write(Session $session, ContentType $type, string $voice): TaggedResponse
    {
        $messages = $session->messages;
        $latest = array_pop($messages);

        $prompt = ($session->draft ? "<current_draft>\n{$session->draft}\n</current_draft>\n\n" : '').($latest['content'] ?? '');

        $response = $this->ask('writer', $prompt, $messages, instructions: $this->writerInstructions($type, $voice));

        return TaggedResponse::parse($response->text, 'draft', $response->usage->input, $response->usage->output);
    }

    /**
     * The questionnaire answers as the opening message of a session.
     */
    public function brief(ContentType $type, Session $session): string
    {
        $lines = ["Here is the brief for a new entry: {$type->title}.", ''];

        foreach ($type->questions as $question) {
            $answer = trim((string) ($session->answers[$question['handle']] ?? ''));

            $lines[] = '**'.$question['label'].'**';
            $lines[] = $answer !== '' ? $answer : '(not answered)';
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    public function writerInstructions(ContentType $type, string $voice): string
    {
        $blueprint = $type->statamicBlueprint()
            ?? throw new InvalidArgumentException("The collection \"{$type->collection}\" no longer exists.");

        $schema = $this->reader->read($blueprint);
        $pattern = $this->patterns->find($type->collection, $schema, $type->blueprint, $type->where, $type->examples);

        return strtr($this->promptFile('writer'), [
            '{{ voice }}' => trim($voice) !== '' ? trim($voice) : 'No guide has been written yet. Write plainly and specifically, and match the existing entries shown below.',
            '{{ type_title }}' => $type->title,
            '{{ type_description }}' => $type->description,
            '{{ type_guidance }}' => $type->guidance,
            '{{ type_checklist }}' => $type->checklist ? '- '.implode("\n- ", $type->checklist) : '- It reads like the existing entries.',
            '{{ fields }}' => $this->describer->describe($schema, $pattern),
            '{{ examples }}' => $this->examples($pattern),
            '{{ images }}' => app(ImageStudio::class)->describe($type),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pattern
     */
    private function examples(array $pattern): string
    {
        if (empty($pattern['examples'])) {
            return 'Nothing has been published here yet, so there are no examples. Follow the fields and the guidance.';
        }

        return collect($pattern['examples'])->map(function (array $example, int $i) {
            $yaml = trim(YAML::dump($example));

            if (mb_strlen($yaml) > self::EXAMPLE_LIMIT) {
                $yaml = mb_substr($yaml, 0, self::EXAMPLE_LIMIT)."\n# (example cut short)";
            }

            return '<example number="'.($i + 1)."\">\n{$yaml}\n</example>";
        })->implode("\n\n");
    }

    /**
     * Send one request to the model chosen in the settings.
     *
     * The agent is the prompt's name: it sets the instructions (unless they
     * are given, filled in), the token limit and the effort. A reply that
     * runs out of room is asked for once more with twice the room. If it
     * still does not fit, a draft or a guide fails rather than being kept
     * half-written, and anything else is kept as far as it got.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, Image>  $images
     *
     * @throws ProviderException
     */
    public function ask(string $agent, string $prompt, array $history = [], array $images = [], ?string $instructions = null, ?int $timeout = null): TextResponse
    {
        $request = new TextRequest($agent, $instructions ?? $this->promptFile($agent), $prompt, Message::list($history), $images, timeout: $timeout);
        $provider = $this->providers->text();
        $response = $provider->text($request);

        if (! $response->truncated()) {
            return $response;
        }

        $limit = $request->resolvedMaxTokens();
        $more = min(self::MAX_TOKENS_CEILING, $limit * 2);

        if ($more > $limit) {
            Log::warning("Ghostwriter: the {$agent} reply ran out of room at {$limit} tokens; asking again with {$more}.", ['provider' => $response->provider, 'model' => $response->model]);

            $response = $provider->text($request->withMaxTokens($more));

            if (! $response->truncated()) {
                return $response;
            }
        }

        if (isset(self::WHOLE[$agent])) {
            throw new Truncated(self::WHOLE[$agent], $response->provider);
        }

        Log::warning("Ghostwriter: the {$agent} reply ran out of room at {$more} tokens; keeping what came back.", ['provider' => $response->provider, 'model' => $response->model]);

        return $response;
    }

    /**
     * A prompt, as published to the project when it has been, or as core
     * ships it.
     */
    private function promptFile(string $name): string
    {
        return $this->prompts->get($name);
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\BriefWriter;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\ImageryAnalyst;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\PhotoResearcher;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\Planner;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\TypeAnalyst;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\VoiceAnalyst;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\VoiceEditor;
use NineteenNinetyFour\Ghostwriter\Ai\Agents\Writer;
use NineteenNinetyFour\Ghostwriter\Blueprints\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
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
 * instructions from the prompt files, choosing the provider and model from
 * config, and turning the answer back into something the rest of the addon
 * can use.
 */
class Studio
{
    /** Examples are trimmed to this many characters each. */
    private const EXAMPLE_LIMIT = 7000;

    public function __construct(
        private SchemaReader $reader,
        private PatternFinder $patterns,
        private SchemaDescriber $describer,
        private Settings $settings,
    ) {}

    /**
     * Whether the configured provider has an API key to call with.
     */
    public function configured(): bool
    {
        $key = config('ai.providers.'.$this->provider().'.key');

        return is_string($key) && trim($key) !== '';
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

        $response = $this->ask(new VoiceAnalyst($this->promptFile('voice-analyst')), $prompt);

        return new TaggedResponse('', trim($response->text), $response->usage->inputTokens, $response->usage->outputTokens);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        $prompt = "<current_guide>\n{$guide}\n</current_guide>\n\nRequest: {$request}";

        $response = $this->ask(new VoiceEditor($this->promptFile('voice-editor')), $prompt, $history);

        return TaggedResponse::parse($response->text, 'document', $response->usage->inputTokens, $response->usage->outputTokens);
    }

    /**
     * Words to search a photo library with for a draft. A page title makes a
     * poor search, so the model names something that can be photographed;
     * without a model to ask, the title has to do.
     */
    public function photoQuery(Session $session): string
    {
        if (! $this->configured() || $session->draft === null) {
            return $session->title();
        }

        try {
            $summary = preg_match('/^(?:summary|excerpt|description|intro):\s*(.+)$/mu', $session->draft, $m) ? trim($m[1], " \t\"'") : '';
            $words = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} -]+/u', ' ', $this->ask(new PhotoResearcher, "Title: {$session->title()}\nSummary: {$summary}")->text) ?? ''));

            return $words !== '' && str_word_count($words) <= 6 ? $words : $session->title();
        } catch (Throwable $exception) {
            report($exception);

            return $session->title();
        }
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

        $analyst = new TypeAnalyst($this->promptFile('type-analyst'));
        $response = $this->ask($analyst, $prompt);
        [$data, $problem] = $this->readType($response->text);

        // An answer that cannot be read gets one more chance, told what was wrong.
        if ($data === null) {
            Log::warning("Ghostwriter: the type analysis for {$collection->handle()} could not be read ({$problem}):\n{$response->text}");

            $response = $this->ask(
                new TypeAnalyst($this->promptFile('type-analyst')),
                "Your answer could not be read: {$problem}. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.",
                [['role' => 'user', 'content' => $prompt], ['role' => 'assistant', 'content' => $response->text]],
            );
            [$data, $problem] = $this->readType($response->text);
        }

        if ($data === null) {
            Log::warning("Ghostwriter: the type analysis for {$collection->handle()} could not be read again ({$problem}):\n{$response->text}");

            throw new InvalidArgumentException('The analysis came back in a form that could not be read. Try again.');
        }

        $handle = $title ? Str::slug($title) : $collection->handle();

        return ContentType::fromArray($handle, array_filter([
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->handle(),
            'examples' => $examples,
            'title' => $title,
        ]) + $data);
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

        $response = $this->ask(new Planner($instructions), trim($steer) !== '' ? "What I am looking for this time: {$steer}" : 'Suggest what is missing.');
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

        $response = (new ImageryAnalyst($this->promptFile('imagery-analyst')))->prompt(
            "Section: {$collectionTitle}\n\nThe attached images, in order:\n{$list}",
            array_column($samples, 'image'),
            provider: $this->provider(),
            model: $this->settings->model(),
            timeout: (int) config('ghostwriter.timeout', 180),
        );

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

        $response = $this->ask(new BriefWriter($instructions), "Working title: {$title}\n\nNotes:\n".(trim($notes) !== '' ? trim($notes) : '(none)'));
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

        $response = $this->ask(new Writer($this->writerInstructions($type, $voice)), $prompt, $messages);

        return TaggedResponse::parse($response->text, 'draft', $response->usage->inputTokens, $response->usage->outputTokens);
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
     * @param  array<int, array{role: string, content: string}>  $history
     */
    private function ask(Agent $agent, string $prompt, array $history = []): AgentResponse
    {
        if ($history) {
            $agent = $agent->withMessages(array_map(
                fn (array $message) => new Message($message['role'], $message['content']),
                $history,
            ));
        }

        return $agent->prompt(
            $prompt,
            provider: $this->provider(),
            model: $this->settings->model(),
            timeout: (int) config('ghostwriter.timeout', 180),
        );
    }

    private function promptFile(string $name): string
    {
        // A project can override any prompt by publishing it.
        $published = resource_path("ghostwriter/prompts/{$name}.md");

        return trim((string) File::get(File::exists($published) ? $published : __DIR__."/../../resources/prompts/{$name}.md"));
    }
}

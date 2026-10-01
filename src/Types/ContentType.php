<?php

namespace NineteenNinetyFour\Ghostwriter\Types;

use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use Statamic\Contracts\Entries\Collection;
use Statamic\Facades\Collection as Collections;
use Statamic\Fields\Blueprint;

/**
 * One kind of content written into a collection: the questions asked before
 * writing, and guidance on what a good one looks like. A collection can have
 * several (an article and a guide, say). The fields themselves are not
 * stored here; they are read from the blueprint each time, so a change to
 * the blueprint is picked up at once.
 */
class ContentType
{
    /**
     * @param  array<int, array{handle: string, label: string, type?: string, instructions?: string, required?: bool, options?: array<int|string, string>}>  $questions
     * @param  array<int, string>  $checklist
     * @param  array<string, mixed>  $where  Narrows which existing entries this type learns from.
     * @param  array<string, mixed>  $defaults  Entry data set on everything written as this type.
     * @param  array<int, string>  $examples  IDs of entries to learn from, instead of the collection's newest.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly string $description,
        public readonly string $collection,
        public readonly array $questions,
        public readonly string $guidance,
        public readonly array $checklist = [],
        public readonly ?string $blueprint = null,
        public readonly array $where = [],
        public readonly array $defaults = [],
        public readonly array $examples = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $handle, array $data): self
    {
        $questions = [];

        foreach ((array) ($data['questions'] ?? []) as $question) {
            if (is_array($question) && isset($question['handle'], $question['label'])) {
                $questions[] = $question;
            }
        }

        return new self(
            handle: $handle,
            title: (string) ($data['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $handle))),
            description: trim((string) ($data['description'] ?? '')),
            collection: (string) ($data['collection'] ?? ''),
            questions: $questions,
            guidance: trim((string) ($data['guidance'] ?? '')),
            checklist: array_values(array_filter((array) ($data['checklist'] ?? []), 'is_string')),
            blueprint: $data['blueprint'] ?? null,
            where: (array) ($data['where'] ?? []),
            defaults: (array) ($data['defaults'] ?? []),
            examples: array_values(array_filter((array) ($data['examples'] ?? []), 'is_string')),
        );
    }

    /** Handle prefix of the built-in type every collection has. */
    public const GENERIC = 'any:';

    /**
     * The type every collection has without being taught anything: a general
     * brief, and no fixed recipe. What is written follows the entries chosen
     * as models when the piece is started, or the brief alone.
     */
    public static function generic(Collection $collection): self
    {
        return new self(
            handle: self::GENERIC.$collection->handle(),
            title: 'Something new',
            description: 'A general brief for anything in '.$collection->title().'. Pick entries to model it on, or describe what you want and let Ghostwriter choose the shape.',
            collection: $collection->handle(),
            questions: [
                ['handle' => 'subject', 'label' => 'What is this about?', 'instructions' => 'The subject, and what the entry is for.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'reader', 'label' => 'Who is it for, and what should they do after reading?', 'type' => 'textarea', 'required' => true],
                ['handle' => 'points', 'label' => 'What must it say?', 'instructions' => 'The facts, figures, names and points to make. Nothing beyond these will be claimed.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'shape', 'label' => 'Anything about its shape or length?', 'instructions' => 'Leave blank to follow the entries it is modelled on.', 'type' => 'text'],
                ['handle' => 'must_not_appear', 'label' => 'What must not appear?', 'type' => 'textarea'],
            ],
            guidance: "There is no set recipe for this entry.\n\nIf example entries are shown, they were chosen as the model: follow their structure block for block and match their length. Keep unchanged any block that is identical across the examples, such as process steps or testimonials, and never reword a quotation.\n\nIf the brief asks for a different shape, the brief wins. With no examples to follow, choose the fields and blocks that suit what the brief asks for, preferring those this collection already uses.",
            checklist: ['Every fact comes from the brief or the conversation.', 'Its structure follows the chosen examples, or suits the brief where there are none.'],
        );
    }

    public function isGeneric(): bool
    {
        return str_starts_with($this->handle, self::GENERIC);
    }

    /**
     * The same type, modelled on different entries: those picked for one
     * particular piece rather than for the type as a whole.
     *
     * @param  array<int, string>  $examples
     */
    public function modelledOn(array $examples): self
    {
        if ($examples === []) {
            return $this;
        }

        return new self($this->handle, $this->title, $this->description, $this->collection, $this->questions, $this->guidance, $this->checklist, $this->blueprint, [], $this->defaults, $examples);
    }

    /**
     * The type as one session uses it: modelled on the entries picked for
     * that piece, and on the blueprint of the entry being edited if any.
     */
    public function forSession(Session $session): self
    {
        $type = $this->modelledOn($session->examples);

        if (! $session->blueprint || $session->blueprint === $type->blueprint) {
            return $type;
        }

        return new self($type->handle, $type->title, $type->description, $type->collection, $type->questions, $type->guidance, $type->checklist, $session->blueprint, $type->where, $type->defaults, $type->examples);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'collection' => $this->collection,
            'blueprint' => $this->blueprint,
            'where' => $this->where ?: null,
            'defaults' => $this->defaults ?: null,
            'examples' => $this->examples ?: null,
            'questions' => $this->questions,
            'guidance' => $this->guidance,
            'checklist' => $this->checklist,
        ], fn ($value) => $value !== null);
    }

    /**
     * What the questionnaire screen needs.
     *
     * @return array<string, mixed>
     */
    public function forQuestionnaire(): array
    {
        return [
            'handle' => $this->handle,
            'title' => $this->title,
            'description' => $this->description,
            'questions' => $this->questions,
            'examples' => $this->examples,
            'generic' => $this->isGeneric(),
        ];
    }

    public function statamicCollection(): ?Collection
    {
        return Collections::findByHandle($this->collection);
    }

    public function statamicBlueprint(): ?Blueprint
    {
        $collection = $this->statamicCollection();

        if (! $collection) {
            return null;
        }

        return $this->blueprint ? $collection->entryBlueprint($this->blueprint) : $collection->entryBlueprint();
    }

    /**
     * Laravel validation rules for the questionnaire.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->questions as $question) {
            $rules['answers.'.$question['handle']] = [
                ($question['required'] ?? false) ? 'required' : 'nullable',
                'string',
                'max:20000',
            ];
        }

        return $rules;
    }
}

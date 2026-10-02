<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;

/**
 * Editing a content type: its name, the questions asked before writing, the
 * guidance given to the writer and the entries it is modelled on.
 */
class TypeController
{
    public function __construct(private TypeRepository $types) {}

    public function edit(string $type): Response
    {
        $type = $this->type($type);
        $blueprint = $this->blueprint($type);

        $fields = $blueprint->fields()->addValues($this->values($type))->preProcess();

        return Inertia::render('ghostwriter::Type', [
            'type' => ['handle' => $type->handle, 'title' => $type->title, 'collection' => $type->statamicCollection()?->title() ?? $type->collection],
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'urls' => [
                'index' => cp_route('ghostwriter.index'),
                'update' => cp_route('ghostwriter.types.update', $type->handle),
                'destroy' => cp_route('ghostwriter.types.destroy', $type->handle),
            ],
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        $type = $this->type($type);

        // A kind with no questions would be the general brief under another name.
        if (collect($request->input('questions', []))->filter(fn ($question) => trim((string) ($question['label'] ?? '')) !== '')->isEmpty()) {
            throw ValidationException::withMessages(['questions' => 'A kind of content needs at least one question.']);
        }

        $fields = $this->blueprint($type)->fields()->addValues($request->all());

        $fields->validate();

        $values = $fields->process()->values()->all();
        $taken = [];

        $this->types->save(ContentType::fromArray($type->handle, [
            'title' => $values['title'],
            'description' => $values['description'] ?? '',
            'questions' => collect($values['questions'] ?? [])->map(function (array $question) use (&$taken) {
                return array_filter([
                    // Kept from before when there is one; made from the wording for a new question.
                    'handle' => $this->handleFor($question, $taken),
                    'label' => $question['label'],
                    'instructions' => $question['instructions'] ?? null,
                    'type' => $question['type'] ?? 'textarea',
                    'required' => (bool) ($question['required'] ?? false),
                ], fn ($value) => $value !== null && $value !== '');
            })->values()->all(),
            'guidance' => $values['guidance'] ?? '',
            'checklist' => $values['checklist'] ?? [],
            'examples' => $values['examples'] ?? [],
            // Not editable on this screen; kept exactly as they were.
            'collection' => $type->collection,
            'blueprint' => $type->blueprint,
            'where' => $type->where,
            'defaults' => $type->defaults,
        ]));

        return response()->json(['saved' => true]);
    }

    public function destroy(string $type): JsonResponse
    {
        $this->types->delete($this->type($type));

        return response()->json(['deleted' => true]);
    }

    /**
     * A question's handle: the one it had, or one made from its wording,
     * unique within the kind. Handles are not shown on this screen; changing
     * one would lose answers already given, so they are left alone.
     *
     * @param  array<string, mixed>  $question
     * @param  array<int, string>  $taken
     */
    private function handleFor(array $question, array &$taken): string
    {
        $base = trim((string) ($question['handle'] ?? '')) ?: (Str::slug((string) $question['label'], '_') ?: 'question');
        $handle = $base;

        for ($n = 2; in_array($handle, $taken, true); $n++) {
            $handle = "{$base}_{$n}";
        }

        return $taken[] = $handle;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ContentType $type): array
    {
        return [
            'title' => $type->title,
            'description' => $type->description,
            'questions' => array_map(fn (array $question) => [
                'label' => $question['label'],
                'handle' => $question['handle'],
                'instructions' => $question['instructions'] ?? null,
                'type' => $question['type'] ?? 'textarea',
                'required' => $question['required'] ?? false,
            ], $type->questions),
            'guidance' => $type->guidance,
            'checklist' => $type->checklist,
            'examples' => $type->examples,
        ];
    }

    /**
     * The edit screen is an ordinary Statamic publish form, so questions are
     * a grid, guidance is a markdown field and examples use the entry picker.
     */
    private function blueprint(ContentType $type): Blueprint
    {
        return BlueprintFacade::makeFromFields([
            'title' => ['type' => 'text', 'display' => 'Name', 'validate' => ['required'], 'width' => 33],
            'description' => ['type' => 'textarea', 'display' => 'Description', 'instructions' => 'Shown when choosing what to write.', 'width' => 66],
            'questions' => [
                'type' => 'grid',
                'display' => 'The brief',
                'instructions' => 'Asked before anything is written. Ask only for what cannot be invented: what happened, who for, what resulted, what must be left out.',
                'mode' => 'stacked',
                'add_row' => 'Add a question',
                'min_rows' => 1,
                'fields' => [
                    ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Question', 'validate' => ['required'], 'width' => 100]],
                    // Kept with each question but not shown: it is made from the wording.
                    ['handle' => 'handle', 'field' => ['type' => 'text', 'display' => 'Handle', 'visibility' => 'hidden']],
                    ['handle' => 'instructions', 'field' => ['type' => 'text', 'display' => 'Hint', 'width' => 66]],
                    ['handle' => 'type', 'field' => ['type' => 'button_group', 'display' => 'Answer', 'options' => ['text' => 'One line', 'textarea' => 'Paragraph'], 'default' => 'textarea', 'width' => 25]],
                    ['handle' => 'required', 'field' => ['type' => 'toggle', 'display' => 'Required', 'width' => 25]],
                ],
            ],
            'guidance' => [
                'type' => 'markdown',
                'display' => 'Guidance for the writer',
                'instructions' => 'Who the reader is, what each part of an entry does and in what order, how long it runs. The fields themselves are read from the blueprint, so there is no need to list them.',
                'buttons' => ['bold', 'italic', 'unorderedlist', 'orderedlist', 'quote'],
                'automatic_line_breaks' => false,
                'smartypants' => false,
            ],
            'checklist' => ['type' => 'list', 'display' => 'Check before handing over', 'instructions' => 'Short statements that must be true of a finished draft.'],
            'examples' => [
                'type' => 'entries',
                'display' => 'Modelled on',
                'instructions' => 'Entries this kind of content should resemble. Leave empty to use the newest published entries in the collection.',
                'collections' => [$type->collection],
                'mode' => 'default',
                'max_items' => 6,
            ],
        ]);
    }

    private function type(string $handle): ContentType
    {
        $type = $this->types->find($handle);

        // The built-in general type has nothing to edit.
        abort_unless($type && ! $type->isGeneric() && $this->types->enabled($type->collection), 404);

        return $type;
    }
}

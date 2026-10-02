<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Images\FieldImages;
use NineteenNinetyFour\Ghostwriter\Images\FieldSlot;
use NineteenNinetyFour\Ghostwriter\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Jobs\MakeImage;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Field;
use Symfony\Component\HttpFoundation\Response;

/**
 * The image button on an assets field: find a photograph, or have one
 * made, for one field on the form being edited.
 */
class ImagesController
{
    public function __construct(
        private ImageRequests $requests,
        private FieldImages $images,
        private ImageStudio $studio,
        private StockSearch $stock,
        private Studio $text,
    ) {}

    /**
     * What the button can offer on this site.
     */
    public function tools(): JsonResponse
    {
        return response()->json([
            'find' => $this->stock->sources() !== [],
            'make' => $this->studio->configured(),
            'suggests' => $this->text->configured(),
        ]);
    }

    /**
     * Start a search (mode "find") or a picture (mode "make").
     */
    public function start(Request $request): JsonResponse
    {
        $slot = $this->slot($request);
        $mode = $request->input('mode');

        if ($mode === 'find') {
            abort_if($this->stock->sources() === [], 422, 'No photo library is switched on. Turn on Openverse in the settings, or add an Unsplash, Pexels or Pixabay key.');

            $terms = PhotoFinder::terms((string) $request->input('words', ''));

            abort_if($terms === [] && ! $this->text->configured() && $slot->title === '', 422, 'Type what the picture should show.');

            $data = $this->requests->create($this->owner($request) + ['mode' => 'find', 'terms' => $terms, 'options' => []]);
            FindImages::start($data['id']);

            return response()->json($this->payload($data));
        }

        if ($mode === 'make') {
            abort_unless($this->studio->configured(), 422, 'No image provider has an API key. Add OPENAI_API_KEY or GEMINI_API_KEY to your .env file.');

            $request->validate(['source' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'], 'direction' => ['nullable', 'string', 'max:2000']]);

            $data = $this->requests->create($this->owner($request) + ['mode' => 'make', 'direction' => trim((string) $request->input('direction', ''))]);

            if ($upload = $request->file('source')) {
                $path = $this->requests->file($data['id'].'-source', $upload->extension() ?: 'png');
                File::put($path, (string) file_get_contents($upload->getRealPath()));
                $data = $this->requests->update($data['id'], ['source' => $path]);
            }

            MakeImage::start($data['id']);

            return response()->json($this->payload($data));
        }

        abort(422, 'Choose to find a photograph or make a picture.');
    }

    public function status(string $id): JsonResponse
    {
        return response()->json($this->payload($this->mine($id)));
    }

    /**
     * A picture that has been made, before it is kept.
     */
    public function preview(string $id): Response
    {
        $data = $this->mine($id);

        abort_unless(! empty($data['file']) && File::exists($data['file']), 404);

        return response()->file($data['file'], ['Content-Type' => $data['mime'] ?? 'image/png']);
    }

    /**
     * Keep a found photograph, or the picture made, as an asset in the field.
     */
    public function use(Request $request, string $id): JsonResponse
    {
        $data = $this->mine($id);
        $slot = FieldSlot::find(...$data['slot']) ?? abort(422, 'That image field is no longer on the page.');

        $this->ensureCanUploadTo($slot);

        try {
            if ($data['mode'] === 'find') {
                $validated = $request->validate(['source' => ['required', 'string'], 'photo' => ['required', 'string', 'max:64'], 'term' => ['nullable', 'string', 'max:200']]);
                $term = (string) ($validated['term'] ?: ($data['terms'][0] ?? ''));
                $asset = $this->images->keepPhoto($slot, $this->stock->fetch($validated['source'], $validated['photo']), $term);
            } else {
                abort_unless(! empty($data['file']) && File::exists($data['file']), 422, 'That picture is no longer here. Make it again.');

                $asset = $this->images->keep($slot, (string) File::get($data['file']), pathinfo($data['file'], PATHINFO_EXTENSION), [
                    'title' => trim((string) ($data['direction'] ?? '')) !== '' ? mb_substr(trim($data['direction']), 0, 80) : $slot->title,
                ]);
            }
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json($this->kept($slot, $asset, (array) $request->input('current', [])));
    }

    /**
     * Saving an image into the field's container is uploading to it, and
     * needs the same permission as uploading by hand.
     */
    private function ensureCanUploadTo(FieldSlot $slot): void
    {
        $container = AssetContainer::find($slot->field['container']) ?? abort(422, 'That field\'s asset container no longer exists.');

        abort_unless(User::current()?->can('store', [Asset::class, $container]), 403, 'You cannot upload to the '.$container->title().' container.');
    }

    /**
     * The field the request is about, from what the form sent.
     */
    private function slot(Request $request): FieldSlot
    {
        $validated = $request->validate([
            'collection' => ['required', 'string', 'max:100'],
            'blueprint' => ['nullable', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'set' => ['nullable', 'string', 'max:100'],
            'entry' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:300'],
            'block_text' => ['nullable', 'string', 'max:20000'],
            'page_text' => ['nullable', 'string', 'max:20000'],
        ]);

        if (! empty($validated['entry'])) {
            $entry = Entry::find($validated['entry']) ?? abort(404);
            abort_unless(User::current()?->can('edit', $entry), 403);
        }

        return FieldSlot::find(...$this->slotArguments($validated)) ?? abort(422, 'Ghostwriter cannot help with this field: it is not an image field in a collection it writes for.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, mixed>
     */
    private function slotArguments(array $validated): array
    {
        return [
            $validated['collection'],
            $validated['blueprint'] ?? null,
            $validated['path'],
            $validated['set'] ?? null,
            $validated['entry'] ?? null,
            (string) ($validated['title'] ?? ''),
            (string) ($validated['block_text'] ?? ''),
            (string) ($validated['page_text'] ?? ''),
        ];
    }

    /**
     * What a request records about who asked and for which field.
     *
     * @return array<string, mixed>
     */
    private function owner(Request $request): array
    {
        $validated = $request->only(['collection', 'blueprint', 'path', 'set', 'entry', 'title', 'block_text', 'page_text']);

        return ['user' => (string) User::current()?->id(), 'slot' => $this->slotArguments($validated)];
    }

    /**
     * @return array<string, mixed>
     */
    private function mine(string $id): array
    {
        $data = $this->requests->find($id) ?? abort(404);

        abort_unless(($data['user'] ?? null) === (string) User::current()?->id(), 403);

        return $data;
    }

    /**
     * The field's new value, with the asset in it, and the meta the form's
     * assets field needs to show it. A placeholder makes way; so does the
     * picture in a single-image field. A field that holds several and has
     * as many as it allows is left as it is: the asset is kept in its
     * container, and `full` says why it is not in the field.
     *
     * @param  array<int, string>  $current
     * @return array<string, mixed>
     */
    private function kept(FieldSlot $slot, Asset $asset, array $current): array
    {
        $max = (int) ($slot->field['max_files'] ?? 0);
        $single = $max === 1;
        $kept = $single ? [] : array_values(array_filter($current, fn ($id) => is_string($id) && ! str_ends_with($id, '::'.Placeholders::PATH) && $id !== Placeholders::PATH));
        $about = ['id' => $asset->id(), 'path' => $asset->path(), 'url' => $asset->url(), 'title' => (string) $asset->get('title')];

        if ($max > 1 && count($kept) >= $max) {
            $container = AssetContainer::find($slot->field['container'])?->title() ?? $slot->field['container'];

            return [
                'asset' => $about,
                'full' => true,
                'message' => "This field is full: it takes {$max} images. The image is saved in the {$container} container; remove an image from the field to make room, then choose it from there.",
            ];
        }

        $field = $this->formField($slot)->setValue([...$kept, $asset->id()])->preProcess();

        return [
            'asset' => $about,
            'full' => false,
            'value' => $field->value(),
            'meta' => $field->meta(),
        ];
    }

    /**
     * The form's own field, so the value is pre-processed the way the
     * publish form expects it.
     */
    private function formField(FieldSlot $slot): Field
    {
        $handle = $slot->field['handle'];

        if ($slot->set === null) {
            return $slot->blueprint->field($handle) ?? abort(422, 'That field is no longer on the blueprint.');
        }

        $sets = (array) $slot->blueprint->field($slot->set['in'])?->get('sets', []);

        foreach ($sets as $key => $group) {
            $set = $key === $slot->set['handle'] && ! isset($group['sets']) ? $group : ($group['sets'][$slot->set['handle']] ?? null);

            foreach ((array) ($set['fields'] ?? []) as $config) {
                if (($config['handle'] ?? null) === $handle && is_array($config['field'] ?? null)) {
                    return new Field($handle, $config['field']);
                }
            }
        }

        abort(422, 'That field is no longer on the blueprint.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'id' => $data['id'],
            'mode' => $data['mode'],
            'status' => $data['status'],
            'error' => $data['error'] ?? null,
            'terms' => $data['terms'] ?? [],
            'options' => $data['options'] ?? [],
            'judged' => (bool) ($data['judged'] ?? false),
            'none_fit' => (bool) ($data['none_fit'] ?? false),
            'with_references' => (bool) ($data['with_references'] ?? false),
            'preview_url' => ! empty($data['file']) ? cp_route('ghostwriter.images.preview', $data['id']) : null,
            'status_url' => cp_route('ghostwriter.images.status', $data['id']),
            'use_url' => cp_route('ghostwriter.images.use', $data['id']),
        ];
    }
}

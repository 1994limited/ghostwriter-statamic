<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftLayouts;
use NineteenNinetyFour\Ghostwriter\Gaps\StatamicLinkTargets;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Symfony\Component\Yaml\Yaml;

/**
 * A gap resolved from its chip, in the draft itself: the page preview and
 * the Text tab show markers as chips, and clicking one lets the editor
 * answer an ask, confirm, change or remove a count, or choose a link,
 * before Use this draft. The answer goes in exactly as typed (core's
 * Gaps\MarkerResolver); no model. It is saved like any draft edit, under
 * the session's lock, and the layouts are re-arranged without a call.
 *
 * A gap resolved here never reaches the form, so Finish this page never
 * lists it; one left for later is untouched and still does.
 */
class GapsController
{
    private const BUSY = 'Ghostwriter is still working on the draft. Try again when it has finished.';

    /** Where an extra item's words are, in a chip's path: `['@extra', itemId, field]`. */
    public const EXTRA = '@extra';

    public function __construct(
        private SessionGuard $sessions,
        private TypeRepository $types,
        private DraftLayouts $layouts,
        private Presenter $presenter,
    ) {}

    public function resolve(Request $request, string $session): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MarkerResolver::KINDS)],
            'hint' => ['present', 'nullable', 'string', 'max:400'],
            'list' => ['nullable', 'string', 'max:400'],
            'occurrence' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // The draft path of the text the chip is in (the Text tab), or none (the preview).
            'path' => ['nullable', 'array', 'max:30'],
            'path.*' => ['string', 'max:100'],
            'value' => ['present', 'nullable', 'string', 'max:5000'],
            // For a link: what a link field holds for the entry (`entry::abc`), where `value` is its address.
            'reference' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $this->session($session);
        $type = $this->types->find($session->kind);

        $session = $this->guarded(fn () => $this->sessions->edit($session->id, $this->viewer(), function (Session $session) use ($validated, $type) {
            abort_if($session->draft === null, 422, 'There is no draft yet.');

            try {
                $data = Draft::parse($session->draft)->data;
            } catch (InvalidArgumentException $exception) {
                abort(422, $exception->getMessage());
            }

            $extras = Extras::fromArray($session->extras);
            $path = $validated['path'] ?? null;
            $texts = $path ? $this->at($data, $extras, $path) : [...MarkerResolver::leaves($data), ...self::extraTexts($extras)];
            $found = MarkerResolver::find($texts, $validated['kind'], (string) ($validated['hint'] ?? ''), $validated['list'] ?? null, (int) ($validated['occurrence'] ?? 0));

            $value = (string) ($validated['value'] ?? '');

            // A link field the draft doesn't hold (the house style puts its
            // sentinel in when the page is built): the choice is kept in the
            // draft by its hint, and goes in wherever that sentinel does.
            if ($found === null && $validated['kind'] === 'link' && ! $path && $value !== '') {
                $before = $session->draft;
                $data = MarkerResolver::chooseLink($data, (string) ($validated['hint'] ?? ''), ($validated['reference'] ?? '') !== '' ? $validated['reference'] : $value, $value);
                $session->draft = trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
                $this->layouts->afterEdit($session, $before, $type);

                return;
            }

            abort_if($found === null, 422, 'That gap isn’t in the draft any more.');

            if ($found['whole'] && ($validated['reference'] ?? '') !== '') {
                $value = (string) $validated['reference'];
            }

            if ($found['path'][0] === self::EXTRA) {
                $this->resolveExtra($session, $extras, $found, $value, $type);

                return;
            }

            $node = &$data;

            foreach ($found['path'] as $step) {
                $node = &$node[$step];
            }

            $node = MarkerResolver::apply($node, $found, $value);
            unset($node);

            $before = $session->draft;
            $session->draft = trim(Yaml::dump($data, 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

            $this->layouts->afterEdit($session, $before, $type);
        }, self::BUSY));

        return response()->json($this->presenter->detail($session));
    }

    /**
     * Entries a link to choose could point at: the hint's best matches, or
     * what the editor typed into "Choose an entry". No model.
     */
    public function links(Request $request, string $session): JsonResponse
    {
        $this->session($session);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'site' => ['nullable', 'string', 'max:100'],
        ]);

        $targets = (new StatamicLinkTargets($validated['site'] ?? null))->search($validated['q'], 6);

        return response()->json(['entries' => array_map(fn (LinkTarget $target) => [
            'title' => $target->title,
            'url' => $target->url,
            // A link field takes the reference; words take the address.
            'value' => $target->value,
        ], $targets)]);
    }

    /**
     * The one text a chip in the Text tab is in.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $path
     * @return list<array{path: list<int|string>, text: string}>
     */
    private function at(array $data, Extras $extras, array $path): array
    {
        if ($path[0] === self::EXTRA) {
            $item = $extras->item($path[1] ?? '');
            $field = $path[2] ?? 'text';
            $text = $item === null ? null : ($field === 'text' ? $item->text : ($item->parts[$field] ?? null));

            return is_string($text) ? [['path' => [self::EXTRA, $item->id, $field], 'text' => $text]] : [];
        }

        $node = $data;
        $steps = [];

        foreach ($path as $step) {
            $step = is_numeric($step) && is_array($node) && array_is_list($node) ? (int) $step : $step;

            if (! is_array($node) || ! array_key_exists($step, $node)) {
                return [];
            }

            $node = $node[$step];
            $steps[] = $step;
        }

        return is_string($node) ? [['path' => $steps, 'text' => $node]] : [];
    }

    /**
     * Each extra item's words: its text, and the parts that aren't in it
     * (a stat's number is in its text, a question isn't).
     *
     * @return list<array{path: list<int|string>, text: string}>
     */
    private static function extraTexts(Extras $extras): array
    {
        $texts = [];

        foreach ($extras->items() as $item) {
            $texts[] = ['path' => [self::EXTRA, $item->id, 'text'], 'text' => $item->text];

            foreach ($item->parts as $part => $words) {
                if (is_string($words) && $words !== '' && ! str_contains($item->text, $words)) {
                    $texts[] = ['path' => [self::EXTRA, $item->id, (string) $part], 'text' => $words];
                }
            }
        }

        return $texts;
    }

    /**
     * The marker resolved in the item, and the rest of the item kept in
     * step: a stat's count is both in its text ("3 areas") and its number
     * ("3"). Confirmed, every count from the same list becomes its value;
     * changed or removed, a stat's text is its new number and its label.
     *
     * @param  array{path: list<int|string>, text: string, kind: string, match: string, occurrence: int, whole: bool}  $found
     */
    private function resolveExtra(Session $session, Extras $extras, array $found, string $value, ?ContentType $type): void
    {
        $item = $extras->item((string) $found['path'][1]);

        abort_if($item === null || $type === null, 422, 'That gap isn’t in the draft any more.');

        $field = (string) $found['path'][2];
        $words = ['text' => $item->text, ...array_map('strval', $item->parts)];
        $words[$field] = MarkerResolver::apply($words[$field], $found, $value);

        if ($found['kind'] === 'check') {
            $marker = Markers::checks($found['match'])[0] ?? null;
            $confirmed = $marker !== null && Markers::normaliseHint($marker['value']) === Markers::normaliseHint($value);
            $sameList = fn (string $text) => (string) preg_replace_callback(Markers::CHECK_PATTERN, fn (array $m) => $marker !== null && Markers::normaliseHint(trim($m[2])) === Markers::normaliseHint($marker['list']) ? trim($m[1]) : $m[0], $text);
            $isStat = isset($item->parts['value'], $item->parts['label']);

            if (! $confirmed && $isStat && $field === 'value') {
                $words['text'] = trim($words['value'].' '.Markers::withoutChecks($words['label']));
            }

            foreach ($words as $name => $text) {
                $words[$name] = $sameList($text);
            }
        } else {
            // The same marker anywhere else in the item.
            foreach ($words as $name => $text) {
                if ($name !== $field && str_contains($text, $found['match'])) {
                    $words[$name] = MarkerResolver::apply($text, [...$found, 'occurrence' => 0], $value);
                }
            }
        }

        $text = trim($words['text']);
        unset($words['text']);

        try {
            $this->layouts->editExtra($session, $item->id, $text, $words, $type);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    private function session(string $id): Session
    {
        try {
            return $this->sessions->find($id, $this->viewer());
        } catch (NotFound) {
            abort(404);
        } catch (NotAllowed) {
            abort(403);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (Busy $busy) {
            abort(409, $busy->messageFor(fn (int|string $id) => Presenter::name((string) $id)));
        } catch (NotFound) {
            abort(404);
        } catch (Refused $refused) {
            abort($refused->status(), $refused->getMessage());
        }
    }

    private function viewer(): Viewer
    {
        return Presenter::viewer();
    }
}

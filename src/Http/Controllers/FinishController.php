<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapRefused;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\GapRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Blueprint;

/**
 * Finish this page, behind the entry's publish form:
 *
 * - check: what is unfinished in the form's values, for the count by Save
 *   and the guide. Found for nothing: no model, no save.
 * - fill: one fix that writes, on a click that says so ("Write around
 *   it", "Write it for me"): one small model call, the text handed back
 *   for the form. It never supplies a fact.
 * - guide: whether the person left the guide open or minimised.
 */
class FinishController
{
    /** The user preference holding how the guide was last left. */
    public const PREFERENCE = 'ghostwriter_finish_guide';

    public const OPEN = 'open';

    public const MINIMISED = 'minimised';

    public function __construct(private EntryGaps $gaps, private TypeRepository $types) {}

    public function check(Request $request): JsonResponse
    {
        [$blueprint, $entry, $values, $collection] = $this->form($request);

        $report = $this->gaps->forForm($blueprint, $values, $entry, $collection, $this->session($request, $entry), $request->input('site'));

        return response()->json($this->gaps->present($report, $blueprint));
    }

    public function fill(Request $request, Studio $studio, BardDialect $bard): JsonResponse
    {
        $request->validate([
            'gap' => ['required', 'string', 'max:2000'],
            'task' => ['required', 'string', Rule::in([FixAction::WriteAround->value, FixAction::WriteForMe->value])],
        ]);

        [$blueprint, $entry, $values, $collection] = $this->form($request);
        $report = $this->gaps->forForm($blueprint, $values, $entry, $collection, $this->session($request, $entry), $request->input('site'));
        $gap = $report->find((string) $request->input('gap'));

        abort_if($gap === null, 422, __('That has already been filled in.'));

        try {
            $ask = $request->input('task') === FixAction::WriteAround->value
                ? GapRequest::writeAround($gap)
                : $this->summary($gap, $blueprint, $values, $entry, $bard);
            $text = (string) $studio->fillGap($ask)->value;
        } catch (GapRefused) {
            abort(422, __('Ghostwriter never fills in a fact. Type it in yourself.'));
        } catch (UnreadableReply $unreadable) {
            abort(422, __('Ghostwriter\'s answer couldn\'t be used. Try again, or write it yourself.'));
        } catch (ProviderException $failed) {
            Log::channel(config('ghostwriter.log_channel'))->warning('Ghostwriter: a Finish this page fix failed: '.$failed->getMessage());
            abort(422, $failed->getMessage());
        }

        return response()->json(['text' => $text, 'excerpt' => $gap->excerpt, 'match' => $gap->meta['match'] ?? null]);
    }

    public function guide(Request $request): JsonResponse
    {
        $state = $request->validate(['state' => ['required', Rule::in([self::OPEN, self::MINIMISED])]])['state'];
        $user = User::current();

        if ($user && $user->getPreference(self::PREFERENCE) !== $state) {
            $user->setPreference(self::PREFERENCE, $state)->save();
        }

        return response()->json(['state' => $state]);
    }

    /**
     * "Write it for me" for a prose field: a blurb from the page's own
     * words, as long as a summary is.
     */
    private function summary(Gap $gap, Blueprint $blueprint, array $values, ?EntryContract $entry, BardDialect $bard): GapRequest
    {
        abort_unless(in_array($gap->kind, [GapKind::Required, GapKind::Expected], true), 422, __('Ghostwriter can\'t write this one.'));

        $context = $this->gaps->context($blueprint, $this->gaps->data($blueprint, $values, $entry));
        $words = [];
        $prose = false;

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($visit->path->equals($gap->path)) {
                $prose = in_array($visit->field->kind, [Kind::Text, Kind::LongText], true);

                continue;
            }

            if (($text = Walk::text($visit, $bard)) !== null && trim($text) !== '') {
                $words[] = $text;
            }
        }

        abort_unless($prose, 422, __('Ghostwriter can\'t write this one.'));
        abort_if(trim(implode('', $words)) === '', 422, __('There are no words on the page to write it from yet.'));

        return GapRequest::summary($gap->label, implode("\n\n", $words), gap: $gap);
    }

    /**
     * The form being checked: its blueprint, the entry (when editing one),
     * its values as the form holds them, and the collection.
     *
     * @return array{Blueprint, ?EntryContract, array<string, mixed>, string}
     */
    private function form(Request $request): array
    {
        $request->validate([
            'collection' => ['required', 'string'],
            'blueprint' => ['nullable', 'string'],
            'entry' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
        ]);

        $entry = $request->input('entry') ? Entry::find((string) $request->input('entry')) : null;
        $collection = Collection::find((string) $request->input('collection'));

        abort_unless($collection && $this->types->enabled($collection->handle()), 404);
        abort_if($request->input('entry') && ! $entry, 404);
        abort_unless($entry
            ? User::current()?->can('edit', $entry)
            : User::current()?->can('create', [EntryContract::class, $collection]), 403);

        $blueprint = $entry?->blueprint()
            ?? ($request->input('blueprint') ? $collection->entryBlueprint((string) $request->input('blueprint')) : null)
            ?? $collection->entryBlueprint();

        abort_unless($blueprint, 404);

        $values = $request->input('values');

        return [$blueprint, $entry, is_array($values) ? $values : [], $collection->handle()];
    }

    private function session(Request $request, ?EntryContract $entry): ?Session
    {
        return $this->gaps->sessionById($request->input('session')) ?? ($entry ? $this->gaps->sessionOf($entry) : null);
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use Throwable;

/**
 * Comments on the draft (core's Review\Comments), as the panel shows them
 * and sends them. Sent comments are conversation messages, so everyone on
 * the piece sees the same ones, in whichever layout is chosen: a comment
 * points at the draft's words (units), not at a block's place.
 */
class DraftComments
{
    private ?GithubFlavoredMarkdownConverter $markdown = null;

    public function __construct(
        private Comments $comments,
        private DraftLayouts $layouts,
        private Layouts $core,
    ) {}

    /**
     * Every comment sent on the piece, for the pins, the chat and the
     * sidebar, in the chosen layout: its state worked out from the
     * conversation, the blocks holding its words, Ghostwriter's reply as
     * escaped markdown, and its before and after. `next` is the number the
     * editor's next pin takes.
     *
     * @return array{next: int, pins: list<array<string, mixed>>}
     */
    public function present(Session $session): array
    {
        $me = Presenter::viewer()->id;
        $pins = [];

        foreach ($this->comments->pins($session) as $pin) {
            $scope = $pin['scope'];

            $pins[] = [
                'number' => $pin['number'],
                'id' => $pin['id'],
                'message' => $pin['message'],
                'answer' => $pin['answer'],
                'status' => $pin['status'],
                'state' => __($pin['state']),
                'kind' => (string) ($scope['kind'] ?? 'block'),
                'units' => array_values(array_map('strval', (array) ($scope['units'] ?? []))),
                'label' => isset($scope['label']) ? (string) $scope['label'] : null,
                'path' => isset($scope['blockPath']) ? (string) $scope['blockPath'] : null,
                'quote' => isset($scope['quote']['exact']) ? (string) $scope['quote']['exact'] : null,
                'body' => $pin['body'],
                'by' => $pin['by'] === null ? null : $this->who($pin['by'], $me),
                'mine' => $pin['by'] !== null && (string) $pin['by'] === (string) $me,
                'reply' => $pin['reply'] === null ? null : $this->html($pin['reply']),
                'changes' => array_map(fn (array $change) => [
                    'unit' => $change['unit'],
                    'diff' => $change['diff'],
                    'layout' => $change['layout'],
                    'filled' => array_map(fn (array $filled) => ['ask' => $filled['ask'], 'value' => $filled['value']], $change['filled']),
                ], $pin['changes']),
                'can_put_back' => $pin['canPutBack'],
                'put_back_by' => $pin['putBack'] !== null ? $this->who($pin['putBack']['by'] ?? '', $me) : null,
                'resolved_by' => $pin['resolved'] !== null ? $this->who($pin['resolved']['by'] ?? '', $me) : null,
                'blocks' => $pin['blocks'],
                'in_layout' => (bool) $pin['inLayout'],
            ];
        }

        return ['next' => Comments::nextNumber($session), 'pins' => $pins];
    }

    /**
     * What a new comment (or "Pin to a block") is about, from where it was
     * made: a block's units, some words in it, or the whole page. Words are
     * anchored to the one unit of the block that holds them; words the
     * draft doesn't have once (a template that reworded them) fall back to
     * the block.
     *
     * @param  array{kind: string, units?: list<string>, label?: ?string, plan?: ?string, path?: ?string, plan_path?: ?string, quote?: ?array{exact?: ?string, prefix?: ?string, suffix?: ?string}}  $input
     *
     * @throws InvalidArgumentException when the block has no words to comment on.
     */
    public function scope(Session $session, ContentType $type, array $input): Scope
    {
        $label = isset($input['label']) && trim((string) $input['label']) !== '' ? mb_substr(trim((string) $input['label']), 0, 120) : null;
        $plan = isset($input['plan']) && $input['plan'] !== '' ? (string) $input['plan'] : $this->layouts->chosen($session)?->id;
        $path = isset($input['path']) && $input['path'] !== '' ? (string) $input['path'] : null;

        if ($input['kind'] === 'page') {
            return Scope::page($label, $plan);
        }

        $units = $this->units($session, $type);
        $extras = Extras::fromArray($session->extras);
        $known = array_values(array_filter(array_unique(array_map('strval', $input['units'] ?? [])), fn (string $id) => $units->get($id) !== null || $extras->item($id) !== null));

        // A block another layout fills from pieces of units or from extras:
        // what that layout's plan places in it (and in the blocks inside it).
        if ($known === [] && isset($input['plan_path']) && $input['plan_path'] !== '' && ($chosen = $this->layouts->chosen($session)) !== null) {
            foreach (Comments::blocksOf($chosen) as $at => $refs) {
                if ($at === $input['plan_path'] || str_starts_with($at, $input['plan_path'].'/')) {
                    array_push($known, ...array_values(array_filter($refs, fn (string $id) => $units->get($id) !== null || $extras->item($id) !== null)));
                }
            }

            $known = array_values(array_unique($known));
        }

        if ($known === []) {
            throw new InvalidArgumentException('That block has no writing of its own to comment on. Comment on the whole page instead.');
        }

        $exact = trim((string) ($input['quote']['exact'] ?? ''));

        if ($input['kind'] === 'text' && $exact !== '') {
            $quote = new TextQuote(mb_substr($exact, 0, TextQuote::MAX_EXACT), (string) ($input['quote']['prefix'] ?? ''), (string) ($input['quote']['suffix'] ?? ''));
            $finder = new QuoteFinder;

            foreach ($known as $id) {
                $text = $units->get($id)?->markdown;

                if ($text !== null && ($match = $finder->find($quote, $text, null, true)) !== null) {
                    return Scope::text($id, $match->fuzzy ? $match->requote($text) : $quote, $label, $plan, $path);
                }
            }
        }

        return Scope::block($known, $label, $plan, $path);
    }

    /**
     * The draft's units now, with their ids.
     */
    public function units(Session $session, ContentType $type): Units
    {
        $site = $this->layouts->context($type->forSession($session), site: false)
            ?? throw new InvalidArgumentException('The collection this was written for no longer exists.');

        return Units::fromDraft(Draft::parse((string) $session->draft)->data, $site->schema, $this->core->richText)->restore($session->units);
    }

    private function who(int|string $id, int|string|null $me): string
    {
        return $me !== null && (string) $id === (string) $me ? __('You') : Presenter::name((string) $id);
    }

    private function ago(mixed $at): ?string
    {
        try {
            return is_string($at) && $at !== '' ? Carbon::parse($at)->diffForHumans() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A note as HTML: markdown, with any HTML in it escaped (C6).
     */
    private function html(string $body): string
    {
        $this->markdown ??= new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return trim($this->markdown->convert($body)->getContent());
    }
}

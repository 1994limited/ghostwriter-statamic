<?php

namespace NineteenNinetyFour\Ghostwriter\Gaps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio as CoreStudio;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestLinks;
use NineteenNinetyFour\Ghostwriter\Suggest\EntryChecks;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Throwable;

/**
 * Finish this page's **Suggest links** on a Statamic entry (SEO layer §12,
 * `few-links`): core's SeoPass::suggestLinksFor() on the publish form's
 * values, in the background (SuggestLinks, as Try again in the Search
 * section), one `seo-editor` and one `seo-verifier` call.
 *
 * What it found is kept in the cache under a token for the page view, for
 * the person who asked: the guide sends the token with each check, and
 * EntryGaps hands the proposals to the gap finder, which makes each link
 * still to make a "Link it" step. Nothing is written into the entry here:
 * Link it links the words in Bard, and nothing is saved until Save.
 */
class LinkSuggestions
{
    /** How long a page view's proposals are kept. */
    public const SECONDS = 86400;

    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(private EntryGaps $gaps, private TypeRepository $types) {}

    /**
     * Starts Suggest links on a form's values; the token the guide polls
     * (status()) and sends back with each check (proposals()).
     *
     * @param  array<string, mixed>  $form  collection, entry, blueprint, site, session, values: as the check gets them.
     */
    public function start(array $form, int|string $by): string
    {
        $token = Str::lower(Str::random(24));

        Cache::put(self::key($token), ['status' => self::WORKING, 'by' => (string) $by, 'form' => $form], self::SECONDS);
        SuggestLinks::start($token);

        return $token;
    }

    /**
     * Where Suggest links is, for whoever asked: working, done (with how
     * many links were found, and why none), or failed; null for anyone else
     * or an unknown token.
     *
     * @return array{status: string, found?: int, none?: string, message?: string}|null
     */
    public function status(string $token, int|string|null $by): ?array
    {
        $state = $this->state($token, $by);

        if ($state === null) {
            return null;
        }

        $proposals = is_array($state['proposals'] ?? null) ? LinkProposals::fromArray($state['proposals']) : null;

        return array_filter([
            'status' => (string) ($state['status'] ?? self::FAILED),
            'found' => $proposals !== null ? count($proposals->links) : null,
            'none' => $proposals?->none,
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
        ], fn ($value) => $value !== null);
    }

    /** What Suggest links found, for whoever asked; null until it has finished. */
    public function proposals(?string $token, int|string|null $by): ?LinkProposals
    {
        $state = $token !== null && $token !== '' ? $this->state($token, $by) : null;

        return ($state['status'] ?? null) === self::DONE && is_array($state['proposals'] ?? null) ? LinkProposals::fromArray($state['proposals']) : null;
    }

    /** The job's work: the two calls on the form as it was when asked. */
    public function run(string $token): void
    {
        $state = Cache::get(self::key($token));

        if (! is_array($state) || ($state['status'] ?? null) !== self::WORKING || ! is_array($state['form'] ?? null)) {
            return;
        }

        try {
            $proposals = $this->find($state['form']);
            Cache::put(self::key($token), ['status' => self::DONE, 'by' => $state['by'], 'proposals' => $proposals->toArray()], self::SECONDS);
        } catch (ProviderException $exception) {
            Log::channel(config('ghostwriter.log_channel'))->warning("Ghostwriter: Suggest links found nothing, as the call failed: {$exception->getMessage()}", ['agent' => 'seo-editor']);
            Cache::put(self::key($token), ['status' => self::FAILED, 'by' => $state['by'], 'message' => __('Ghostwriter couldn\'t look for pages to link to just now. Try again, or add a link yourself.')], self::SECONDS);
        } catch (Throwable $exception) {
            Log::channel(config('ghostwriter.log_channel'))->error('Ghostwriter: Suggest links failed: '.$exception->getMessage(), ['exception' => $exception]);
            Cache::put(self::key($token), ['status' => self::FAILED, 'by' => $state['by'], 'message' => __('Something went wrong.')], self::SECONDS);
        }
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function find(array $form): LinkProposals
    {
        $collection = Collection::find((string) ($form['collection'] ?? ''));
        $entry = is_string($form['entry'] ?? null) && $form['entry'] !== '' ? Entry::find($form['entry']) : null;

        if ($collection === null) {
            return LinkProposals::none(LinkProposals::NO_PLACE);
        }

        $blueprint = $entry?->blueprint()
            ?? (is_string($form['blueprint'] ?? null) && $form['blueprint'] !== '' ? $collection->entryBlueprint($form['blueprint']) : null)
            ?? $collection->entryBlueprint();
        $site = is_string($form['site'] ?? null) && $form['site'] !== '' ? $form['site'] : ($entry?->locale() ?? Site::default()->handle());
        $values = is_array($form['values'] ?? null) ? $form['values'] : [];
        $data = $this->gaps->data($blueprint, $values, $entry);
        $page = $this->gaps->context($blueprint, $data, $collection->handle(), $entry?->id(), site: $site);
        $type = $this->types->forCollection($collection->handle())->first() ?? TypeRepository::generic($collection);
        $writer = app(Studio::class)->writerContextFor($type, $this->voice());
        $links = new LinkContext(
            app(LinkIndex::class),
            new StatamicLinks,
            $collection->handle(),
            $site,
            $entry ? EntryChecks::ref($entry) : null,
            $writer->kind,
            $writer->voice,
            (string) (Site::get($site)?->locale() ?? 'en'),
        );
        $pass = new SeoPass(logger: Log::channel(config('ghostwriter.log_channel')), studio: app(CoreStudio::class));
        $title = is_scalar($data['title'] ?? null) ? (string) $data['title'] : (string) $entry?->get('title');

        return $pass->suggestLinksFor($page, $links, $title);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(string $token, int|string|null $by): ?array
    {
        $state = preg_match('/^[a-z0-9]{24}$/', $token) === 1 ? Cache::get(self::key($token)) : null;

        return is_array($state) && $by !== null && (string) ($state['by'] ?? '') === (string) $by ? $state : null;
    }

    private function voice(): string
    {
        try {
            return (string) app(GuideStore::class)->guide(Guide::VOICE)->body;
        } catch (Throwable) {
            return '';
        }
    }

    private static function key(string $token): string
    {
        return 'ghostwriter.finish.links.'.$token;
    }
}

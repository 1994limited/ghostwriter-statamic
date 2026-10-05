<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Jobs\SuggestLinks;
use NineteenNinetyFour\Ghostwriter\Tests\Concerns\LinkSites;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Finish this page's **Suggest links** on Statamic (SEO layer §12,
 * `few-links`): "Link to your other pages" starts the two link calls on the
 * form's values in the background; once they have finished, each link
 * found is a step ("Link “…” to Contact us?", Link it · Skip) for the
 * person who asked, by the token the guide sends with each check; linked
 * words lose their step; nothing found says so. Nothing is saved. Every
 * model reply is the fake's.
 */
final class SuggestLinksTest extends TestCase
{
    use LinkSites;

    private const LEAD = 'We cut back the grasses that have stood all winter, lift and divide the perennials that have grown too big, and mulch the borders while the soil is still damp. Roses are pruned to an outward bud, and climbers are tied in along the wires.';

    private const BOOKING = 'If you would like a winter visit, tell us about your garden and we will arrange a first walk round before the cold sets in.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->linkSites();
        config(['ghostwriter.collections' => ['site_pages', 'visits']]);
        $this->saveEntry('contact', 'site_info', 'Contact us', ['summary' => 'Tell us about your garden and book a winter visit in Northumberland.']);

        Collection::make('visits')->title('Visits')->routes('/visits/{slug}')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body', 'buttons' => ['h2', 'h3', 'bold', 'italic', 'anchor']]],
        ]])->save();

        app(TypeRepository::class)->save(TypeRepository::make('visits', ['title' => 'Visit', 'collection' => 'visits', 'questions' => []]));

        Entry::make()->id('winter')->collection('visits')->slug('winter-visits')->published(true)->data(['title' => 'Winter visits', 'body' => self::bard(self::BOOKING)])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownLinkSites();

        parent::tearDown();
    }

    /**
     * About 330 words: eight paragraphs, then "Booking a visit".
     *
     * @return list<array<string, mixed>>
     */
    private static function bard(string $booking, ?string $href = null): array
    {
        $nodes = array_fill(0, 8, ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => self::LEAD]]]);
        $nodes[] = ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Booking a visit']]];
        $words = 'tell us about your garden';
        [$before, $after] = explode($words, $booking, 2) + [1 => ''];
        $nodes[] = ['type' => 'paragraph', 'content' => $href === null ? [['type' => 'text', 'text' => $booking]] : [
            ['type' => 'text', 'text' => $before],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]], 'text' => $words],
            ['type' => 'text', 'text' => $after],
        ]];

        return $nodes;
    }

    /**
     * The publish form's values for the entry, with its body as given.
     *
     * @param  list<array<string, mixed>>|null  $body
     * @return array<string, mixed>
     */
    private function values(?array $body = null): array
    {
        $entry = Entry::find('winter');

        return $entry->blueprint()->fields()->addValues(['title' => 'Winter visits', 'body' => $body ?? $entry->get('body')])->preProcess()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function check(array $values, ?string $links = null): array
    {
        return $this->postJson(cp_route('ghostwriter.finish.check'), ['collection' => 'visits', 'entry' => 'winter', 'values' => $values, 'links' => $links])->assertOk()->json('gaps');
    }

    private function scriptLinks(): void
    {
        // Units: u1 the title, u2 the lead paragraphs, u3 "Booking a visit".
        $this->ai->respondStructured('seo-editor', ['notes' => 'Winter visits; Contact fits the booking call.', 'links' => [
            ['unit' => 'u3', 'exact' => 'tell us about your garden', 'prefix' => '', 'target' => 'e1', 'hint' => '', 'why' => 'An invitation to get in touch.'],
        ], 'markers' => [], 'title' => '', 'description' => '']);
        $this->ai->respondStructured('seo-verifier', ['verdicts' => [['notes' => 'Right page.', 'id' => 'l1', 'verdict' => 'keep', 'reason' => 'Fits.']]]);
    }

    /** Suggest links pressed on "Link to your other pages"; its token once it has finished. */
    private function suggest(array $values): string
    {
        $gap = collect($this->check($values))->firstWhere('kind', 'few-links');
        $token = (string) $this->postJson(cp_route('ghostwriter.finish.links'), ['collection' => 'visits', 'entry' => 'winter', 'values' => $values, 'gap' => $gap['id']])
            ->assertOk()
            ->assertJsonPath('status', 'working')
            ->json('token');

        // On the sync queue it runs after the response; run it here if it hasn't.
        if ($this->getJson(cp_route('ghostwriter.finish.links.status', $token))->json('status') === 'working') {
            $this->runJob(new SuggestLinks($token));
        }

        return $token;
    }

    public function test_link_to_your_other_pages_offers_suggest_links_first(): void
    {
        $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries']);
        $gap = collect($this->check($this->values()))->firstWhere('kind', 'few-links');

        $this->assertSame(['suggest-links', 'focus', 'dismiss'], array_column($gap['fixes'], 'action'));
        $this->assertSame(['Suggest links', 'Add a link', 'Skip'], array_column($gap['fixes'], 'label'));
        $this->assertSame('model', $gap['fixes'][0]['cost']);
        $this->assertTrue($gap['fixes'][0]['primary']);
        $this->assertSame('Finding pages to link to…', $gap['running']);
        $this->ai->assertNothingSent();
    }

    public function test_each_link_found_is_a_step_for_whoever_asked_and_nothing_is_saved(): void
    {
        $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries']);
        $this->scriptLinks();
        $values = $this->values();
        $token = $this->suggest($values);

        $this->assertSame(['seo-editor', 'seo-verifier'], array_map(fn (TextRequest $request) => $request->agent, $this->ai->requests()));
        $this->assertStringContainsString('Contact us (Information)', $this->ai->prompted('seo-editor')[0]->prompt, 'Any routable page of the site, key pages too.');
        $this->assertStringNotContainsString('Winter visits (Visits)', $this->ai->prompted('seo-editor')[0]->prompt, 'Never the page itself.');
        $this->getJson(cp_route('ghostwriter.finish.links.status', $token))->assertOk()->assertJson(['status' => 'done', 'found' => 1]);

        $gaps = collect($this->check($values, $token));
        $step = $gaps->firstWhere('kind', 'link-proposed');

        $this->assertNull($gaps->firstWhere('kind', 'few-links'), 'The link found is the step now.');
        $this->assertSame('Link “tell us about your garden” to Contact us? An invitation to get in touch.', $step['message']);
        $this->assertSame('Link “tell us about your garden” to Contact us?', $step['step']);
        $this->assertSame('body', $step['dotted']);
        $this->assertSame('tell us about your garden', $step['hint']);
        $this->assertSame(['link', 'dismiss'], array_column($step['fixes'], 'action'));
        $this->assertSame(['Link it', 'Skip'], array_column($step['fixes'], 'label'));
        $this->assertSame('statamic://entry::contact', $step['fixes'][0]['value'], 'As Bard stores a link to the entry.');
        $this->assertTrue($step['meta']['inline']);
        $this->assertSame('suggestion', $step['severity']);
        $this->assertSame(self::bard(self::BOOKING), Entry::find('winter')->get('body'), 'Nothing is saved.');

        // Linked in the form: the step goes, and so does few-links.
        $linked = collect($this->check($this->values(self::bard(self::BOOKING, 'statamic://entry::contact')), $token));
        $this->assertNull($linked->firstWhere('kind', 'link-proposed'));
        $this->assertNull($linked->firstWhere('kind', 'few-links'));

        // Someone else's token shows them nothing.
        $this->actingAs(User::make()->email('other@example.com')->assignRole('tester')->save());
        $this->getJson(cp_route('ghostwriter.finish.links.status', $token))->assertNotFound();
        $this->assertNotNull(collect($this->check($values, $token))->firstWhere('kind', 'few-links'));
    }

    public function test_nothing_close_enough_says_so_and_offers_a_link_by_hand(): void
    {
        Entry::find('contact')->delete();
        $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries']);
        $values = $this->values();
        $token = $this->suggest($values);

        $this->ai->assertNothingSent();
        $this->getJson(cp_route('ghostwriter.finish.links.status', $token))->assertJson(['status' => 'done', 'found' => 0, 'none' => 'no-candidates']);
        $gap = collect($this->check($values, $token))->firstWhere('kind', 'few-links');

        $this->assertSame('No pages close enough to link to. Add a link by hand where one fits, or skip this.', $gap['message']);
        $this->assertSame(['Add a link', 'Skip'], array_column($gap['fixes'], 'label'));
    }

    public function test_a_failed_call_says_so(): void
    {
        $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries']);
        $this->ai->respond('seo-editor', fn () => throw new ProviderException('The provider is busy.', 'fake'));
        $token = $this->suggest($this->values());

        $this->getJson(cp_route('ghostwriter.finish.links.status', $token))->assertJson(['status' => 'failed', 'message' => 'Ghostwriter couldn\'t look for pages to link to just now. Try again, or add a link yourself.']);
    }

    public function test_only_a_page_with_no_links_can_ask(): void
    {
        $this->signInWith(['access ghostwriter', 'view visits entries', 'edit visits entries']);
        $values = $this->values(self::bard(self::BOOKING, 'statamic://entry::contact'));

        $this->postJson(cp_route('ghostwriter.finish.links'), ['collection' => 'visits', 'entry' => 'winter', 'values' => $values, 'gap' => 'few-links|body||0'])->assertStatus(422);
        $this->ai->assertNothingSent();
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use NineteenNinetyFour\Ghostwriter\Content\ContentScanner;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\Jobs\GenerateVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Jobs\RefineVoiceGuide;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use NineteenNinetyFour\Ghostwriter\WorkStates;

class VoiceTest extends TestCase
{
    private const PARAGRAPH = 'When on-site search is done right, not only will it help your customers find the items they need but it will also give them that gentle nudge when making product decisions. This is a win all round when it comes to sales. However, offer up a complex product range and your customers will likely be left feeling overwhelmed and less likely to round up their journey. Those high end sales? Vanished. Challenge accepted.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeArticlesCollection();
        $this->makeArticle('faceted-search', 'Intuitive Faceted Search', self::PARAGRAPH);
        $this->makeArticle('dealer-finder', 'Dealer Finder', str_replace('search', 'finder', self::PARAGRAPH));
    }

    public function test_the_scanner_reads_prose_and_leaves_the_plumbing(): void
    {
        $samples = app(ContentScanner::class)->samples();

        $this->assertCount(2, $samples);

        $text = $samples->firstWhere('title', 'Intuitive Faceted Search')['text'];

        $this->assertStringContainsString('## The Problem', $text);
        $this->assertStringContainsString('Those high end sales? Vanished.', $text);
        $this->assertStringContainsString('Property searches', $text);
        $this->assertStringContainsString('A summary line about Intuitive Faceted Search', $text);
        $this->assertStringNotContainsString('articles/faceted-search.jpg', $text);
    }

    public function test_generating_writes_the_guide_from_the_samples(): void
    {
        $this->ai->respond('voice-analyst', "# Tone of voice\n\n## Who is talking, to whom\n\nWe, to you.");

        $this->runJob(new GenerateVoiceGuide);

        $this->assertStringContainsString('We, to you.', app(GuideStore::class)->guide(Guide::VOICE)->body);
        $this->assertSame('idle', app(GuideStore::class)->state(Guide::VOICE)->status);
        $this->assertCount(2, app(GuideStore::class)->state(Guide::VOICE)->scanned);

        $this->ai->assertSent('voice-analyst', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'Those high end sales? Vanished.'));
    }

    public function test_generating_with_nothing_to_read_fails_with_a_reason(): void
    {

        $this->runJob(new GenerateVoiceGuide(['no-such-collection']));

        $this->assertSame('failed', app(GuideStore::class)->state(Guide::VOICE)->status);
        $this->assertFalse(app(GuideStore::class)->guide(Guide::VOICE)->exists());
        $this->ai->assertNotSent('voice-analyst');
    }

    public function test_refining_applies_the_change_and_records_the_reply(): void
    {
        app(GuideStore::class)->saveGuide(new Guide(Guide::VOICE, "# Tone of voice\n\nOriginal."));
        app(WorkStates::class)->changeGuide(Guide::VOICE, fn (GuideState $state) => $state->addMessage('user', 'Ban the word synergy.'));

        $this->ai->respond('voice-editor', "<reply>Added it.</reply>\n<document>\n# Tone of voice\n\nNever say synergy.\n</document>");

        $this->runJob(new RefineVoiceGuide);

        $this->assertStringContainsString('Never say synergy.', app(GuideStore::class)->guide(Guide::VOICE)->body);
        $this->assertSame('Added it.', app(GuideStore::class)->state(Guide::VOICE)->messages[1]['content']);

        $this->ai->assertSent('voice-editor', fn (TextRequest $prompt) => str_contains($prompt->prompt, 'Original.') && str_contains($prompt->prompt, 'Ban the word synergy.'));
    }

    public function test_a_refinement_that_only_asks_a_question_leaves_the_guide_alone(): void
    {
        app(GuideStore::class)->saveGuide(new Guide(Guide::VOICE, "# Tone of voice\n\nOriginal."));
        app(WorkStates::class)->changeGuide(Guide::VOICE, fn (GuideState $state) => $state->addMessage('user', 'Make it better.'));

        $this->ai->respond('voice-editor', '<reply>Better in what way?</reply>');

        $this->runJob(new RefineVoiceGuide);

        $this->assertStringContainsString('Original.', app(GuideStore::class)->guide(Guide::VOICE)->body);
    }

    public function test_the_scan_endpoint_starts_the_job_and_reports_working(): void
    {
        Bus::fake([GenerateVoiceGuide::class]);
        $this->signIn();

        $this->postJson(cp_route('ghostwriter.voice.scan'), ['collections' => ['articles']])
            ->assertOk()
            ->assertJsonPath('status', 'working');

        Bus::assertDispatchedAfterResponse(GenerateVoiceGuide::class, fn ($job) => $job->collections === ['articles']);

        // On a real queue with no worker, the screen says so after half a minute.
        $this->getJson(cp_route('ghostwriter.voice.status'))->assertJsonPath('waiting', null);
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'ghostwriter']);
        $this->travel(31)->seconds();
        $this->getJson(cp_route('ghostwriter.voice.status'))->assertJsonPath('waiting', 'Still waiting for a queue worker to pick this up. Is “php artisan queue:work --queue=ghostwriter” running?');
    }

    public function test_nothing_is_sent_without_an_api_key(): void
    {
        Bus::fake([GenerateVoiceGuide::class]);
        $this->withoutKeys('anthropic');
        $this->signIn();

        $this->postJson(cp_route('ghostwriter.voice.scan'))->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(GenerateVoiceGuide::class);
    }

    public function test_the_guide_can_be_saved_by_hand(): void
    {
        $this->signIn();

        $this->patchJson(cp_route('ghostwriter.voice.update'), ['document' => "# Ours\n\nEdited by hand."])
            ->assertOk()
            ->assertJsonPath('exists', true);

        $this->assertStringContainsString('Edited by hand.', app(GuideStore::class)->guide(Guide::VOICE)->body);
    }

    public function test_users_without_the_permission_are_turned_away(): void
    {
        $this->signIn(permitted: false);

        $this->get(cp_route('ghostwriter.index'))->assertForbidden();
        $this->postJson(cp_route('ghostwriter.voice.scan'))->assertForbidden();
    }

    public function test_a_failed_image_style_run_stays_explained_until_the_next(): void
    {
        $this->signIn();

        app(WorkStates::class)->changeGuide(Guide::IMAGERY, fn (GuideState $state) => $state->fail('Not enough images.'));

        // Seen once: still there, for whoever comes back to it.
        $this->get(cp_route('ghostwriter.imagery.show'))->assertOk();
        $this->getJson(cp_route('ghostwriter.imagery.status'))
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Not enough images.');
    }
}

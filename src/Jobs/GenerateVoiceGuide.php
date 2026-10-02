<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Content\ContentScanner;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Throwable;

/**
 * Reads a sample of the site's published content and writes the tone of
 * voice guide from it, replacing any guide already there.
 */
class GenerateVoiceGuide implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, string>|null  $collections
     */
    public function __construct(public ?array $collections = null) {}

    public function subject(): ?string
    {
        return 'guide:voice';
    }

    public function handle(ContentScanner $scanner, Studio $studio, GuideStore $guides, WorkStates $states): void
    {
        $this->allowTimeToFinish();

        try {
            $samples = $scanner->samples($this->collections);

            if ($samples->isEmpty()) {
                $states->changeGuide(Guide::VOICE, fn (GuideState $state) => $state->fail('There is no published content long enough to learn a voice from. Publish a few entries, or pick different collections.'));

                return;
            }

            $response = $studio->analyseVoice($samples);

            $guides->saveGuide(new Guide(Guide::VOICE, (string) $response->document));

            $states->changeGuide(Guide::VOICE, function (GuideState $state) use ($samples) {
                $state->succeed();
                $state->messages = [];
                $state->scanned = $samples->map(fn (array $sample) => ['title' => $sample['title'], 'collection' => $sample['collection']])->all();
            });
        } catch (Throwable $exception) {
            report($exception);

            $states->changeGuide(Guide::VOICE, fn (GuideState $state) => $state->fail($exception->getMessage()));
        }
    }
}

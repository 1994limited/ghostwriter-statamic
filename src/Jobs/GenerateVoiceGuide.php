<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Content\ContentScanner;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;
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

    public function handle(ContentScanner $scanner, Studio $studio, VoiceGuide $guide, VoiceState $state): void
    {
        $this->allowTimeToFinish();

        try {
            $samples = $scanner->samples($this->collections);

            if ($samples->isEmpty()) {
                $state->update(['status' => VoiceState::FAILED, 'error' => 'There is no published content long enough to learn a voice from. Publish a few entries, or pick different collections.']);

                return;
            }

            $response = $studio->analyseVoice($samples);

            $guide->save((string) $response->document);

            $state->update([
                'status' => VoiceState::IDLE,
                'error' => null,
                'task' => null,
                'messages' => [],
                'scanned' => $samples->map(fn (array $sample) => ['title' => $sample['title'], 'collection' => $sample['collection']])->all(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $state->update(['status' => VoiceState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }
}

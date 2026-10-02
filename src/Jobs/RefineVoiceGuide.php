<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use NineteenNinetyFour\Ghostwriter\WorkStates;
use Throwable;

/**
 * Applies the latest request in the voice guide's refine conversation.
 */
class RefineVoiceGuide implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function subject(): ?string
    {
        return 'guide:voice';
    }

    public function handle(Studio $studio, GuideStore $guides, WorkStates $states): void
    {
        $this->allowTimeToFinish();

        try {
            $messages = $guides->state(Guide::VOICE)->messages;
            $request = array_pop($messages);

            $response = $studio->refineVoice($guides->guide(Guide::VOICE)->body, $messages, (string) ($request['content'] ?? ''));

            if ($response->document !== null) {
                $guides->saveGuide(new Guide(Guide::VOICE, $response->document));
            }

            $states->changeGuide(Guide::VOICE, function (GuideState $state) use ($response) {
                $state->addMessage('assistant', $response->reply !== '' ? $response->reply : 'Done.');
                $state->succeed();
            });
        } catch (Throwable $exception) {
            report($exception);

            $states->changeGuide(Guide::VOICE, fn (GuideState $state) => $state->fail($exception->getMessage()));
        }
    }
}

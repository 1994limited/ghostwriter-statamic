<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceState;
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

    public function handle(Studio $studio, VoiceGuide $guide, VoiceState $state): void
    {
        $this->allowTimeToFinish();

        try {
            $messages = $state->get()['messages'];
            $request = array_pop($messages);

            $response = $studio->refineVoice($guide->get(), $messages, (string) ($request['content'] ?? ''));

            if ($response->document !== null) {
                $guide->save($response->document);
            }

            $state->addMessage('assistant', $response->reply !== '' ? $response->reply : 'Done.');
            $state->update(['status' => VoiceState::IDLE, 'error' => null, 'task' => null]);
        } catch (Throwable $exception) {
            report($exception);

            $state->update(['status' => VoiceState::FAILED, 'error' => $exception->getMessage(), 'task' => null]);
        }
    }
}

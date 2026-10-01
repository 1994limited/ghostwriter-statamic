<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Drafts\Draft;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use NineteenNinetyFour\Ghostwriter\Voice\VoiceGuide;
use Throwable;

/**
 * Sends a session's latest message to the writer and stores what comes back:
 * its reply, and the draft if it wrote or changed one.
 */
class RunSessionTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId) {}

    public function handle(SessionRepository $sessions, TypeRepository $types, Studio $studio, VoiceGuide $guide, ?ImageStudio $images = null): void
    {
        $this->allowTimeToFinish();

        $images ??= app(ImageStudio::class);
        $session = $sessions->find($this->sessionId);

        if (! $session) {
            return;
        }

        try {
            $type = $types->find($session->type)
                ?? throw new InvalidArgumentException("The content type \"{$session->type}\" no longer exists.");

            $response = $studio->write($session, $type->forSession($session), $guide->get());

            $reply = $response->reply;
            $before = $session->draft;

            if ($response->document !== null) {
                // A draft that does not parse is still kept, so nothing the
                // model wrote is lost; the problem is reported alongside it.
                try {
                    Draft::parse($response->document);
                } catch (InvalidArgumentException $exception) {
                    $reply = trim($reply."\n\n(".$exception->getMessage().' Ask me to fix it.)');
                }

                $session->draft = $response->document;
            }

            // Images the writer asked for: photographs to offer or fill in,
            // or an image to have made. The draft decides which fields exist.
            if ($response->images !== null && $session->draft !== null) {
                $session = $images->carryOut($session, $type->forSession($session), $response->images);
            }

            $session->addMessage('assistant', $reply !== '' ? $reply : 'I have updated the draft.');

            // A turn that hands nothing back and ends on a question is the
            // writer waiting on its colleague, which the panel makes plain.
            $session->messages[array_key_last($session->messages)]['asks'] = $response->document === null && str_contains($reply, '?');

            // What this turn did to the draft, for the conversation's log.
            if ($response->document !== null && $response->document !== $before) {
                $session->messages[array_key_last($session->messages)]['draft'] = [
                    'change' => $before === null ? 'written' : 'updated',
                    'words' => str_word_count($response->document),
                    'was' => $before === null ? null : str_word_count($before),
                ];
            }
            $session->usage['input'] += $response->inputTokens;
            $session->usage['output'] += $response->outputTokens;
            $session->status = Session::IDLE;
            $session->error = null;
        } catch (Throwable $exception) {
            report($exception);

            $session->status = Session::FAILED;
            $session->error = $exception->getMessage();
        }

        $sessions->save($session);
    }
}

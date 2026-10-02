<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Throwable;

/**
 * Makes one image for a session's draft and records the asset it became.
 */
class GenerateImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $sessionId, public string $key, public string $direction = '', public ?string $source = null) {}

    public function handle(SessionStore $sessions, SessionGuard $guard, TypeRepository $types, ImageStudio $studio): void
    {
        $this->allowTimeToFinish();

        $session = $sessions->find($this->sessionId);

        if (! $session) {
            return;
        }

        try {
            $type = $types->find($session->kind)
                ?? throw new InvalidArgumentException("The content type \"{$session->kind}\" no longer exists.");

            $asset = $studio->generate($session, $type->forSession($session), $this->key, $this->direction, $this->source);

            $result = ['status' => 'done', 'path' => $asset->path(), 'url' => $asset->url(), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            $result = ['status' => 'failed', 'error' => $exception->getMessage()];
        }

        if ($this->source) {
            File::delete($this->source);
        }

        // The conversation may have moved on while the image was being made,
        // so only this image's record is written to the session as it stands
        // now, under its lock. A piece removed meanwhile stays removed.
        $guard->change($this->sessionId, function (Session $session) use ($result) {
            $session->images[$this->key] = $result + ['direction' => $this->direction, 'credit' => null] + ($session->images[$this->key] ?? []);
        });
    }
}

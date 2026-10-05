<?php

namespace NineteenNinetyFour\Ghostwriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use NineteenNinetyFour\Ghostwriter\Gaps\LinkSuggestions;

/**
 * Finish this page's "Suggest links": one `seo-editor` and one
 * `seo-verifier` call on the publish form's values (LinkSuggestions). The
 * guide shows "Finding pages to link to…" until it has finished, then a
 * step for each link found, or says none was.
 */
class SuggestLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInBackground;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $token) {}

    public function subject(): ?string
    {
        return 'links:'.$this->token;
    }

    public function handle(LinkSuggestions $suggestions): void
    {
        $this->allowTimeToFinish();

        $suggestions->run($this->token);
    }
}

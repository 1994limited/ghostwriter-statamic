<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Toast;

/**
 * One publish guard for Finish this page and stock photos (core's
 * PublishReadiness): a page can't go live while it holds a fact still to
 * add, a link still to choose or to a page that's gone, an image
 * placeholder, template text left in, or a stock photo previewed but not
 * licensed.
 *
 * Statamic fires EntrySaving on every save, publishing a working copy and
 * scheduled entries included, so: when the entry will be published (now or
 * on a future date) and something blocks, the save is refused with one
 * message per field, by the form's dotted path. Saving unpublished (a
 * draft) always works. `ghostwriter.publish.on_unfinished` (or the settings
 * screen) set to "warn" lets it through with one warning naming
 * everything.
 */
class PublishGuard
{
    public function __construct(private EntryGaps $gaps) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaving::class, [self::class, 'handle']);
    }

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry->published() || ! $entry->blueprint()) {
            return;
        }

        $readiness = $this->gaps->readiness($entry);

        if ($readiness->ready()) {
            return;
        }

        $translate = fn (Message $message) => $this->gaps->text($message);

        if ($readiness->warns()) {
            Toast::error($this->gaps->text($readiness->message($translate)))->duration(12000);

            return;
        }

        throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $readiness->byField($translate)));
    }
}

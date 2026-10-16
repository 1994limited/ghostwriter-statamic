<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Toast;
use Statamic\Statamic;

/**
 * One publish guard for Finish this page and stock photos (core's
 * PublishReadiness): a page can't go live while it holds a fact still to
 * add, a link still to choose or to a page that's gone, an image
 * placeholder, template text left in, or a stock photo previewed but not
 * licensed.
 *
 * Statamic fires EntrySaving on every save, from anywhere: the publish
 * form, publishing a working copy, an import, a console command, another
 * addon. The guard only holds back what an editor publishes in the Control
 * Panel, which is where Ghostwriter's drafts are finished:
 *
 * - Saves outside a Control Panel request (console commands, queued jobs,
 *   imports, the front end) are never refused or warned about. Code that
 *   saves entries has its own reasons, and an old link to a deleted page
 *   must not stop it.
 * - In a collection Ghostwriter doesn't write for, only a stock photo
 *   previewed through Ghostwriter but not licensed is held back; the
 *   other gaps (links to pages that are gone, template text) aren't
 *   Ghostwriter's to police there.
 *
 * Then, when the entry will be published (now or on a future date) and
 * something blocks, the save is refused with one message per field, by the
 * form's dotted path. Saving unpublished (a draft) always works.
 * `ghostwriter.publish.on_unfinished` (or the settings screen) set to
 * "warn" lets it through with one warning naming everything.
 */
class PublishGuard
{
    public function __construct(private EntryGaps $gaps, private TypeRepository $types) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaving::class, [self::class, 'handle']);
    }

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry->published() || ! $entry->blueprint() || ! Statamic::isCpRoute()) {
            return;
        }

        $readiness = $this->gaps->readiness($entry, everything: $this->types->enabled((string) $entry->collectionHandle()));

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

<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\LockTimeout;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\FormBaseline;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * Opens an entry that already exists for editing in conversation. What its
 * form holds now becomes the session's draft, unsaved typing included, and
 * changes are asked for in the same way as on something new.
 */
class EntryController
{
    public function __construct(
        private TypeRepository $types,
        private SessionGuard $guard,
        private SessionStore $sessions,
        private Lock $lock,
        private SchemaReader $reader,
        private EntrySimplifier $simplifier,
        private Presenter $presenter,
        private FormBaseline $baseline,
    ) {}

    public function session(Request $request, Entry $entry): JsonResponse
    {
        abort_unless($this->types->enabled($entry->collectionHandle()), 404);
        abort_unless(User::current()?->can('edit', $entry), 403);

        $found = $this->sessionFor($entry);
        $me = ($id = User::current()?->id()) !== null ? (string) $id : null;

        // Held while the session is read and changed, so a message sent at
        // the same moment isn't lost or run over.
        try {
            $session = $this->lock->run('session:'.$found->id, fn () => $this->carryOn($found, $request, $entry, $me));
        } catch (LockTimeout $timeout) {
            abort(409, $timeout->getMessage());
        }

        return response()->json($this->presenter->detail($session));
    }

    /**
     * The session as it stands now, ready to edit the entry: carried on where
     * it was, or with the entry as its form holds it now as the draft.
     */
    private function carryOn(Session $found, Request $request, Entry $entry, ?string $me): Session
    {
        $session = $this->sessions->find($found->id) ?? $found;

        // A run that stopped without finishing is not still working (CRA-2).
        $session->recoverIfStale(app(DomainOptions::class), now());

        // A conversation already editing this entry, with changes asked for
        // but not yet put into it, carries on where it was; otherwise, or
        // when asked to start again, the entry as its form holds it now is
        // the draft.
        $fresh = $request->boolean('fresh');

        if ($session->isWorking() || (! $fresh && (string) $session->source === (string) $entry->id() && $session->appliedAt === null && $this->wasEditing($session))) {
            return $session;
        }

        // From the form as it stands, which may have been edited by hand
        // since Ghostwriter last saw it, saved or not.
        $data = $this->baseline->data($entry, $request->input('values'));

        $session->source = (string) $entry->id();
        $session->editing = true;
        $session->variant = $entry->blueprint()->handle();
        $session->draft = trim(YAML::dump(['title' => (string) ($data['title'] ?? $entry->get('title'))] + $this->simplifier->simplify($data, $this->reader->read($entry->blueprint()))));
        $session->status = Session::IDLE;
        $session->error = null;
        $session->appliedAt = null;
        $session->touch($me);

        if ($session->messages === [] || ! $this->wasEditing($session) || $fresh) {
            $session->addMessage('user', $fresh && $this->wasEditing($session)
                ? 'Start again from the entry as it stands now. Its content as it stands is the current draft.'
                : 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.', $me, now: now());
            $session->addMessage('assistant', 'I have the entry as it stands. Tell me what to change.', extra: ['editing' => true], now: now());
        }

        return $this->sessions->save($session);
    }

    private function wasEditing(Session $session): bool
    {
        foreach ($session->messages as $message) {
            if (! empty($message['editing'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The conversation this entry was written in, where there was one, so
     * its history is to hand; otherwise a new one with the general brief.
     */
    private function sessionFor(Entry $entry): Session
    {
        $id = $entry->id();
        $collection = $entry->collectionHandle();
        // Everyone's when conversations are shared; otherwise only the
        // person's own are theirs to pick up again.
        $all = collect($this->guard->visible(Presenter::viewer()));

        $existing = $all->first(fn (Session $session) => (string) $session->source === (string) $id || (string) $session->recordId === (string) $id)
            ?? $all->first(fn (Session $session) => $session->source === null
                && $session->recordId === null
                && $session->draft !== null
                && $session->title() === (string) $entry->get('title')
                && $this->types->find($session->kind)?->group === $collection);

        return $existing ?? Session::start(Format::Statamic, ContentType::GENERIC.$collection, [], User::current()?->id(), now: now()->toImmutable());
    }
}

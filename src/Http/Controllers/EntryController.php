<?php

namespace NineteenNinetyFour\Ghostwriter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Http\Presenter;
use NineteenNinetyFour\Ghostwriter\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Sessions\SessionRepository;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * Opens an entry that already exists for editing in conversation. The
 * entry's saved content becomes the session's draft, and changes are asked
 * for in the same way as on something new.
 */
class EntryController
{
    public function __construct(
        private TypeRepository $types,
        private SessionRepository $sessions,
        private SchemaReader $reader,
        private EntrySimplifier $simplifier,
        private Presenter $presenter,
    ) {}

    public function session(Request $request, Entry $entry): JsonResponse
    {
        abort_unless($this->types->enabled($entry->collectionHandle()), 404);
        abort_unless(User::current()?->can('edit', $entry), 403);

        $session = $this->sessionFor($entry);

        // A conversation already editing this entry, with changes asked for
        // but not yet put into it, carries on where it was; otherwise, or
        // when asked to start again, the entry as saved is the draft.
        $fresh = $request->boolean('fresh');

        if ($session->status === Session::WORKING || (! $fresh && $session->source === $entry->id() && $session->appliedAt === null && $this->wasEditing($session))) {
            return response()->json($this->presenter->detail($session));
        }

        // From the entry as saved, which may have been edited by hand since
        // Ghostwriter last saw it.
        $session->source = $entry->id();
        $session->blueprint = $entry->blueprint()->handle();
        $session->draft = trim(YAML::dump(['title' => (string) $entry->get('title')] + $this->simplifier->simplify($entry->data()->all(), $this->reader->read($entry->blueprint()))));
        $session->status = Session::IDLE;
        $session->error = null;
        $session->appliedAt = null;

        if ($session->messages === [] || ! $this->wasEditing($session) || $fresh) {
            $session->addMessage('user', $fresh && $this->wasEditing($session)
                ? 'Start again from the entry as it is saved now. Its content as it stands is the current draft.'
                : 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.');
            $session->addMessage('assistant', 'I have the entry as it stands. Tell me what to change.');
            $session->messages[array_key_last($session->messages)]['editing'] = true;
        }

        $this->sessions->save($session);

        return response()->json($this->presenter->detail($session));
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
        // Only the person's own conversations are theirs to pick up again.
        $all = $this->sessions->visibleTo(User::current());

        $existing = $all->first(fn (Session $session) => $session->source === $id || $session->entryId === $id)
            ?? $all->first(fn (Session $session) => $session->source === null
                && $session->entryId === null
                && $session->draft !== null
                && $session->title() === (string) $entry->get('title')
                && $this->types->find($session->type)?->collection === $collection);

        return $existing ?? Session::start(ContentType::GENERIC.$collection, [], User::current()?->id());
    }
}

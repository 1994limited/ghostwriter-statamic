<?php

namespace NineteenNinetyFour\Ghostwriter\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * Turns a finished draft into a saved, unpublished entry.
 *
 * The default implementation reads the collection's blueprint and fills
 * whatever fields it finds. Bind your own to take over completely.
 */
interface EntryWriter
{
    public function write(Draft $draft, ContentType $type, ?User $user = null): Entry;
}

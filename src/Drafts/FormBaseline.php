<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use Statamic\Contracts\Entries\Entry;

/**
 * What an entry holds as far as editing goes: what its publish form holds
 * now, unsaved typing included, where the form sent its values, and the
 * entry as saved where it did not. Changes are asked for against this and
 * put back over it, so nothing typed into the form by hand is undone.
 */
class FormBaseline
{
    /** Kept on the entry itself, not in its data. */
    private const NOT_DATA = ['slug', 'date', 'parent', 'published'];

    /**
     * @param  mixed  $values  The publish form's values, as the form holds them.
     * @return array<string, mixed>
     */
    public function data(Entry $entry, mixed $values): array
    {
        $saved = $entry->data()->all();

        if (! is_array($values) || $values === []) {
            return $saved;
        }

        $values = array_diff_key($values, array_flip(self::NOT_DATA));

        // The form holds each field the way its fieldtype shows it; process
        // turns that back into what would be saved, as saving the form would.
        $processed = $entry->blueprint()->fields()->addValues($values)->process()->values()->all();

        return array_merge($saved, array_intersect_key($processed, $values));
    }
}

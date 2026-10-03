<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * What DraftValues built: the entry's data in storage form, with the notes
 * on it and what it leaves for a person (Finish this page's list).
 */
final class BuiltValues
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $notes
     * @param  array<int, array<string, mixed>>  $specs  SchemaReader's fields.
     */
    public function __construct(
        public readonly array $data,
        public readonly array $notes,
        public readonly SessionGaps $left,
        public readonly array $specs,
        public readonly Schema $schema,
    ) {}
}

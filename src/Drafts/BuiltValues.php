<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoProvenance;

/**
 * What DraftValues built: the entry's data in storage form, with the notes
 * on it and what it leaves for a person (Finish this page's list), the
 * SEO text Ghostwriter wrote into it (for the session, on apply), and the
 * address to set on a new entry (the form's `slug`; null: leave it).
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
        public readonly SeoProvenance $written = new SeoProvenance,
        public readonly ?string $slug = null,
    ) {}
}

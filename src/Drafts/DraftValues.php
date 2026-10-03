<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use NineteenNinetyFour\Ghostwriter\Images\ImageStudio;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Blueprint;

/**
 * The entry's data as "Use this draft" would put it into the form: the
 * draft built into the blueprint's fields, the house style and placeholders
 * on a new entry, the form's own values under an edit, and the images
 * chosen in the panel. Apply and the page preview both start here, so the
 * preview renders exactly what apply would set.
 *
 * Nothing here changes the session or saves an entry. The one thing apply
 * may write is the striped placeholder image, once per container; the
 * preview passes a sink that only uses one already there.
 */
class DraftValues
{
    public function __construct(
        private SchemaReader $reader,
        private EntryLayouts $layouts,
        private ImageStudio $images,
        private EntryMerger $merger,
        private HouseFinish $finish,
        private FormBaseline $baseline,
        private KeptBardSets $sets,
    ) {}

    /**
     * @param  mixed  $values  The publish form's values, as the form holds them.
     * @param  Entry|null  $original  The entry being edited; null for a new one.
     * @param  AssetSink|null  $placeholders  Where placeholder images come from; null for apply's own.
     */
    public function build(Session $session, ContentType $type, Draft $draft, Blueprint $blueprint, mixed $values, ?Entry $original, ?AssetSink $placeholders = null): BuiltValues
    {
        $specs = $this->reader->read($blueprint);
        $schema = Schema::fromSpecs($specs);

        if ($original) {
            // Editing an entry: only the writing changes. Its images, links,
            // settings and block IDs come from the form as it stands, unsaved
            // changes included, not from what this kind of entry usually has;
            // so do the sets in its Bard fields that the draft never held.
            $built = $this->layouts->build($draft->data, $schema);
            $form = $this->baseline->data($original, $values);
            $data = $this->sets->keep($this->merger->merge($built->data, $form, $specs), $form, $specs);
            $notes = $built->notes;
            $left = SessionGaps::fromDraft($built);
        } else {
            $pattern = $this->layouts->pattern($schema, $type->group, $type->variant, $type->where, $type->examples);
            $built = $this->layouts->build($draft->data, $schema, $pattern, $type->defaults);
            $data = $built->data;

            // An image already chosen in the form stays: no placeholder,
            // nor anything the model entries suggest, goes over it. One
            // chosen in the panel still goes in below.
            $form = $this->baseline->values($blueprint, $values);

            foreach ($schema as $field) {
                if ($field->type === 'assets' && ! empty($form[$field->handle])) {
                    $data[$field->handle] = $form[$field->handle];
                }
            }

            // What the model entries agree on place by place, and a striped
            // placeholder where an image is still to come. The entry has no
            // ID yet, so links to itself wait.
            $finished = $this->finish->finish($data, $schema, $pattern, null, $draft->title(), $placeholders);
            $data = $finished['data'];
            $notes = [...$built->notes, ...$finished['notes']];
            $left = SessionGaps::fromDraft($built, $finished['places'], $finished['placeholders']);
        }

        $data = $this->images->place(['title' => $draft->title()] + $data, $session, $specs);

        return new BuiltValues($data, $notes, $left, $specs, $schema);
    }
}

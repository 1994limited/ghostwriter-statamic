<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
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
            $built = $this->layouts->build(self::words($draft->data), $schema);
            $form = $this->baseline->data($original, $values);
            $data = $this->sets->keep($this->merger->merge($built->data, $form, $specs), $form, $specs);
            $notes = $built->notes;
            $left = SessionGaps::fromDraft($built);
        } else {
            $pattern = $this->layouts->pattern($schema, $type->group, $type->variant, $type->where, $type->examples);
            $built = $this->layouts->build(self::words($draft->data), $schema, $pattern, $type->defaults);
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

        // Links chosen from the preview for fields the draft doesn't hold
        // (a button's link): kept in the session's draft by hint, put in
        // wherever the house style's sentinel for it turned up. The notes
        // stop saying they are still to choose.
        if (($chosen = self::chosenLinks($session)) !== []) {
            $data = MarkerResolver::withChosenLinks($data, $chosen);
            $notes = self::withoutChosen($notes, $chosen, $data);
        }

        return new BuiltValues($data, $notes, $left, $specs, $schema);
    }

    /**
     * The draft's values without the links chosen for fields it doesn't
     * hold (`gw_links`), which aren't a field to build.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function words(array $data): array
    {
        unset($data[MarkerResolver::CHOSEN_LINKS]);

        return $data;
    }

    /**
     * The build's notes without the links since chosen: "Still to choose by
     * hand: Hero: Image; Hero: Button link." loses "Hero: Button link" once
     * a "button link" is chosen, and "(link still to choose)" places go
     * once no link is left to choose.
     *
     * @param  array<int, string>  $notes
     * @param  array<string, mixed>  $chosen  From MarkerResolver::chosenLinks().
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function withoutChosen(array $notes, array $chosen, array $data): array
    {
        $left = str_contains((string) json_encode($data), Markers::LINK_PREFIX);
        $out = [];

        foreach ($notes as $note) {
            if (preg_match('/\A(Still to (?:choose|set) by hand[^:]*: )(.*)\.\z/su', $note, $match) !== 1) {
                $out[] = $note;

                continue;
            }

            $items = array_values(array_filter(explode('; ', $match[2]), function (string $item) use ($chosen, $left) {
                $field = preg_match('/: ([^:]+)\z/u', $item, $named) === 1 ? Markers::normaliseHint($named[1]) : null;

                return ! (($field !== null && isset($chosen[$field])) || (! $left && str_ends_with($item, '(link still to choose)')));
            }));

            if ($items !== []) {
                $out[] = $match[1].implode('; ', $items).'.';
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{link?: mixed, url?: string}>
     */
    private static function chosenLinks(Session $session): array
    {
        try {
            return $session->draft === null ? [] : MarkerResolver::chosenLinks(Draft::parse($session->draft)->data);
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}

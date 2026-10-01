<?php

namespace NineteenNinetyFour\Ghostwriter\Images;

use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Fields\Blueprint;

/**
 * One assets field on one publish form, as the image button sees it: the
 * collection and blueprint, the field, the replicator set it sits in if
 * any, and what the pictures already in that place on other entries look
 * like.
 *
 * Words come first from the block the field sits in, since a picture
 * belongs to what is beside it, and then from the whole page around it.
 */
class FieldSlot
{
    /** Pictures shown to a model as the style to match. */
    public const REFERENCES = 3;

    /** Entries looked through for those pictures, newest first, until three are found. */
    private const ENTRIES = 150;

    private const MAX_CHARS = 6000;

    private const RASTER = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<int, Asset>|null */
    private ?array $references = null;

    /**
     * @param  array<string, mixed>  $field  The field's spec, as SchemaReader reads it.
     * @param  array<string, mixed>|null  $set  The set it sits in: handle, display.
     */
    private function __construct(
        public readonly Collection $collection,
        public readonly Blueprint $blueprint,
        public readonly array $field,
        public readonly ?array $set,
        public readonly ?Entry $entry,
        public readonly string $title,
        public readonly string $blockText,
        public readonly string $pageText,
    ) {}

    /**
     * The slot for a field on a form, or null where the image button has
     * no business: not an assets field that takes images, or not in a
     * collection Ghostwriter writes for.
     *
     * @param  string  $path  The field's path on the form: "featured_image", or "page_builder.2.image".
     */
    public static function find(string $collection, ?string $blueprint, string $path, ?string $set, ?string $entryId, string $title, string $blockText, string $pageText): ?self
    {
        $found = Collections::findByHandle($collection);

        if (! $found || ! app(TypeRepository::class)->enabled($collection)) {
            return null;
        }

        $sheet = ($blueprint ? $found->entryBlueprint($blueprint) : null) ?? $found->entryBlueprint();
        $schema = app(SchemaReader::class)->read($sheet);
        $handle = (string) last(explode('.', $path));
        $top = (string) explode('.', $path)[0];
        $field = null;
        $inSet = null;

        foreach ($schema as $spec) {
            if ($spec['handle'] === $handle && $spec['type'] === 'assets' && ! str_contains($path, '.')) {
                $field = $spec;
            } elseif ($spec['handle'] === $top && $spec['kind'] === 'blocks' && $set !== null && isset($spec['sets'][$set])) {
                $field = collect($spec['sets'][$set]['fields'])->first(fn (array $candidate) => $candidate['handle'] === $handle && $candidate['type'] === 'assets');
                $inSet = $field ? ['handle' => $set, 'display' => $spec['sets'][$set]['display'], 'in' => $top] : null;
            }
        }

        if (! $field || ! ($field['images'] ?? true) || empty($field['container'])) {
            return null;
        }

        $trim = fn (string $text) => mb_strlen(trim($text)) > self::MAX_CHARS ? mb_substr(trim($text), 0, self::MAX_CHARS).'…' : trim($text);

        return new self($found, $sheet, $field, $inSet, $entryId ? Entries::find($entryId) : null, trim($title), $trim($blockText), $trim($pageText));
    }

    /**
     * "Block: Field" inside a page builder, or the field's name at the top.
     */
    public function label(): string
    {
        return ($this->set ? $this->set['display'].': ' : '').($this->field['display'] ?: $this->field['handle']);
    }

    /**
     * Pictures in the same place on the collection's other entries: the
     * same field, in the same kind of set. Failing that, the same field in
     * a sibling set of the same family ("link_grid_bottom" for
     * "link_grid_centre").
     *
     * @return array<int, Asset>
     */
    public function references(): array
    {
        if ($this->references !== null) {
            return $this->references;
        }

        $container = AssetContainer::find($this->field['container']);
        $handle = $this->field['handle'];
        $family = $this->set ? preg_replace('/[_-][^_-]+$/u', '', $this->set['handle']) : null;
        $found = [];
        $kin = [];

        $entries = Entries::query()
            ->where('collection', $this->collection->handle())
            ->where('published', true)
            ->get()
            ->reject(fn (Entry $entry) => $this->entry && $entry->id() === $this->entry->id())
            ->sortByDesc(fn (Entry $entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->take(self::ENTRIES);

        foreach ($entries as $entry) {
            $values = $this->set === null
                ? [[$this->set, $entry->get($handle)]]
                : collect((array) $entry->get($this->set['in']))
                    ->filter(fn ($block) => is_array($block) && ($block['enabled'] ?? true) !== false && isset($block['type']))
                    ->map(fn (array $block) => [$block['type'], $block[$handle] ?? null])
                    ->all();

            foreach ($values as [$type, $value]) {
                $path = is_array($value) ? ($value[0] ?? null) : $value;
                $asset = is_string($path) && $path !== '' && $path !== Placeholders::PATH ? $container?->asset($path) : null;

                if (! $asset || ! in_array(strtolower((string) $asset->extension()), self::RASTER, true)) {
                    continue;
                }

                if ($this->set === null || $type === $this->set['handle']) {
                    $found[$asset->id()] ??= $asset;
                } elseif ($family !== null && is_string($type) && str_starts_with($type, $family)) {
                    $kin[$asset->id()] ??= $asset;
                }
            }

            if (count($found) >= self::REFERENCES) {
                break;
            }
        }

        return $this->references = array_slice(array_values($found ?: $kin), 0, self::REFERENCES);
    }

    /**
     * Where a new picture for this field is filed: the field's own folder,
     * or beside the pictures already there.
     */
    public function folder(): string
    {
        if (! empty($this->field['folder'])) {
            return trim($this->field['folder'], '/');
        }

        $reference = $this->references()[0] ?? null;

        return $reference ? trim(dirname($reference->path()), './') : 'ghostwriter';
    }
}

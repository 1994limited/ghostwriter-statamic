<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\Usage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * A ledger record as the Control Panel shows it: on the image field's
 * badge, in the asset editor, the License & replace step and the Stock
 * images screen. Never a key, a token or a provider's signed address.
 */
class StockPresenter
{
    public function __construct(private StockLibraries $libraries, private CompStore $comps) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(StockImage $image): array
    {
        $licence = $image->licence();
        $requested = $image->toArray()['licence_requested'] ?? null;
        $hasComp = $image->comp() !== null && ($this->comps->isFile($image->comp()) ? $this->comps->get($image->comp()) !== null : true);

        return [
            'id' => $image->id,
            'state' => $image->state(),
            'unlicensed' => $image->isUnlicensed(),
            'library' => $image->library,
            'library_label' => $this->libraries->label($image->library),
            'paid' => $this->libraries->isPaid($image->library),
            'external_id' => $image->externalId,
            'title' => $image->title,
            'asset' => $image->asset->volume.'::'.$image->asset->path,
            'asset_url' => $this->assetUrl($image),
            'comp_url' => $hasComp ? cp_route('ghostwriter.stock.comp', $image->id) : null,
            'comp_expired' => $image->is(StockImage::PREVIEW) && ! $hasComp,
            'comp_keep_until' => self::date($image->compKeepUntil()),
            'may_refresh' => $image->is(StockImage::PREVIEW) && ! $hasComp && $image->mayRefreshComp(),
            'credit_line' => $image->creditLine,
            'credit_url' => $image->creditUrl,
            'licence_type' => $image->licenceType,
            'editorial' => $image->editorial,
            'restrictions' => $image->restrictions,
            'product_type' => $image->productType,
            'replaced' => $image->isReplaced(),
            'error' => $image->error(),
            'requested' => is_array($requested) ? ['by' => $requested['by']['name'] ?? null, 'at' => $requested['at'] ?? null] : null,
            'inserted_by' => $image->insertedBy?->name,
            'inserted_at' => self::date($image->insertedAt),
            'licence' => $licence ? [
                'order_id' => $licence->orderId,
                'cost' => $licence->cost?->label(),
                'estimated' => $licence->estimated,
                'licensed_by' => $licence->licensedBy,
                'licensed_at' => self::date($licence->licensedAt),
                'option' => $licence->option,
            ] : null,
            'usages' => array_map(fn (Usage $usage) => $this->usage($usage), $image->usages()),
            'can_license' => self::canLicense(),
        ];
    }

    /**
     * Whether the signed-in person may license stock images: it spends from
     * the site's account, so it is a permission of its own.
     */
    public static function canLicense(): bool
    {
        return (bool) User::current()?->can('license stock images');
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(Usage $usage): array
    {
        $entry = $usage->ownerType === 'entry' ? Entry::find((string) $usage->ownerId) : null;

        return [
            'owner' => $usage->ownerType.':'.$usage->ownerId,
            'title' => $entry ? (string) $entry->get('title') : __('An entry that no longer exists'),
            'url' => $entry?->editUrl(),
            'field' => $usage->label ?? $usage->field,
            'site' => $usage->site,
            'live' => $usage->live,
        ];
    }

    private function assetUrl(StockImage $image): ?string
    {
        $container = AssetContainer::find($image->asset->volume);

        return $container?->asset($image->asset->path)?->thumbnailUrl('small') ?? null;
    }

    private static function date(?DateTimeImmutable $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }
}

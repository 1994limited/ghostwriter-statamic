<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\HistoryEvent;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImageStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Account;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use Throwable;

/**
 * "License & replace" (design §6, §8.3) as the Control Panel asks for it:
 * the confirm step's options, cost, notices and credit line, then
 * core's StockImages::license() with Statamic's asset replacer, and its
 * failures in plain words. Also "Request licence", "Refresh preview" and
 * "Download again and replace".
 */
class Licensing
{
    public const CREDIT_NOTE = 'If this page is news, a blog post or other editorial use, show this credit next to the image.';

    public function __construct(private Ledger $ledger, private StockLibraries $libraries, private CompStore $comps, private StockImageStore $store, private Lock $lock) {}

    /**
     * What the confirm step shows: the licence options with what each
     * costs from this account, any editorial restrictions and seat
     * notices, and the credit line that will be stored.
     *
     * @return array<string, mixed>
     */
    public function confirm(StockImage $image): array
    {
        $library = $this->library($image);
        $quotes = $library->quotes($image->externalId);

        try {
            $account = $library->account();
        } catch (Throwable) {
            $account = null;
        }

        return [
            'library' => $library->label(),
            'external_id' => $image->externalId,
            'options' => array_map(fn (Quote $quote) => $this->option($library, $quote, $account), $quotes),
            'restrictions' => $image->restrictions,
            'editorial' => $image->editorial,
            'credit_line' => $image->creditLine,
            'credit_note' => __(self::CREDIT_NOTE),
            'already_used' => count($image->usages()) > 1 ? __('Used on :count pages. One licence covers them all within its terms.', ['count' => count($image->usages())]) : null,
        ];
    }

    /**
     * Licenses the photo with the option confirmed, and swaps the stand-in
     * for the licensed file.
     *
     * @return array{ok: bool, message: string, record: StockImage, notice?: ?string, quote?: array<string, mixed>}
     */
    public function license(StockImage $image, string $option): array
    {
        $library = $this->library($image);
        $replacer = new StatamicAssetReplacer($library->label());
        $comp = $image->comp();

        try {
            $quote = collect($library->quotes($image->externalId))->first(fn (Quote $quote) => $quote->option === $option)
                ?? throw new PhotoUnavailable(__('That licence option isn\'t offered any more. Choose again.'));

            $this->ledger->images()->quoted($image->id, $quote, Ledger::person());
            $record = $this->ledger->images()->license($image->id, $library, $quote, $replacer, Ledger::person());
        } catch (LicensingUncertain) {
            return ['ok' => false, 'message' => self::uncertain($library->label()), 'record' => $this->ledger->images()->get($image->id)];
        } catch (QuoteChanged $changed) {
            return ['ok' => false, 'message' => $changed->getMessage(), 'record' => $this->ledger->images()->get($image->id)]
                + ($changed->quote ? ['quote' => $this->option($library, $changed->quote, null)] : []);
        } catch (NotConnected $lost) {
            return ['ok' => false, 'message' => $lost->getMessage(), 'record' => $this->ledger->images()->get($image->id), 'connect' => true];
        } catch (Conflict|PhotoUnavailable $refused) {
            return ['ok' => false, 'message' => $refused->getMessage(), 'record' => $this->ledger->images()->get($image->id)];
        }

        $this->comps->forget($comp);

        if (! $record->isReplaced()) {
            return ['ok' => false, 'message' => (string) $record->error(), 'record' => $record];
        }

        return ['ok' => true, 'message' => __('Licensed. The preview has been replaced with the full image.'), 'record' => $record, 'notice' => $replacer->notice()];
    }

    /**
     * "Download again and replace", for a licence bought whose file
     * couldn't be put in place: never bought again.
     *
     * @return array{ok: bool, message: string, record: StockImage}
     */
    public function replaceAgain(StockImage $image): array
    {
        $library = $this->library($image);
        $record = $this->ledger->images()->replaceAgain($image->id, $library, new StatamicAssetReplacer($library->label()), Ledger::person());

        return $record->isReplaced()
            ? ['ok' => true, 'message' => __('Licensed. The preview has been replaced with the full image.'), 'record' => $record]
            : ['ok' => false, 'message' => (string) $record->error(), 'record' => $record];
    }

    /**
     * Settles a licence whose outcome wasn't known, from the licences the
     * library holds for the photo.
     */
    public function reconcile(StockImage $image): StockImage
    {
        $library = $this->library($image);

        return $this->ledger->images()->reconcile($image->id, fn (StockImage $image) => $library->findLicences($image->externalId), Ledger::person());
    }

    /**
     * "Refresh preview": the comp downloaded again, once, on an editor's
     * click, after the provider's comp period ended.
     */
    public function refresh(StockImage $image): StockImage
    {
        $library = $this->library($image);

        if (! $image->mayRefreshComp()) {
            throw new Conflict(__('This preview has been refreshed once already. License it or remove it.'));
        }

        $preview = $library->preview($image->externalId);
        $comp = ($library->capabilities()->previewStorage === Capabilities::STORAGE_NONE) ? $preview->url : ($preview->file ? $this->comps->put($preview->file) : null);

        if ($comp === null) {
            throw new PhotoUnavailable(__('The library didn\'t send a preview. Try again later.'));
        }

        $old = $image->comp();
        $record = $this->ledger->images()->compRefreshed($image->id, $comp, $preview->keepUntil, Ledger::person());
        $this->comps->forget($old);

        return $record;
    }

    /**
     * "Request licence", from someone who may not license: the record is
     * flagged with who asked and when, and goes to the top of the Previews
     * tab. Its history says so too.
     */
    public function request(StockImage $image): StockImage
    {
        return $this->lock->run('stock:'.$image->id, function () use ($image) {
            $current = $this->store->find($image->id) ?? $image;
            $person = Ledger::person();
            $now = new \DateTimeImmutable;
            $data = $current->toArray(Format::Statamic);
            $data['licence_requested'] = ['by' => $person?->toArray(), 'at' => Format::Statamic->stamp($now)];
            $data['history'][] = HistoryEvent::make('licence_requested', $now, $person)->toArray(Format::Statamic);

            return $this->store->save(StockImage::fromArray($data, Format::Statamic));
        });
    }

    /**
     * The words for an outcome nobody knows: never buy it again.
     */
    public static function uncertain(string $library): string
    {
        return __('We couldn\'t confirm the purchase. Ghostwriter will check with :library in a few minutes; don\'t buy it again.', ['library' => $library]);
    }

    private function library(StockImage $image): LicensableLibrary
    {
        return $this->libraries->licensable($image->library)
            ?? throw new PhotoUnavailable(__(':library isn\'t available. A manager can check it in Ghostwriter\'s settings.', ['library' => $this->libraries->label($image->library)]));
    }

    /**
     * One licence option at confirm: its name and what it costs in words.
     *
     * @return array<string, mixed>
     */
    private function option(LicensableLibrary $library, Quote $quote, ?Account $account): array
    {
        return [
            'option' => $quote->option,
            'name' => $quote->licenceName,
            'extended' => $quote->extended,
            'cost' => $this->costLine($library, $quote, $account),
            'notices' => $this->notices($library, $quote),
        ];
    }

    /**
     * "Uses 1 of your 742 remaining downloads (Getty Premium Access, resets
     * 1 Nov)", "Uses 3 of your 40 iStock credits", or that the cost isn't
     * known before licensing.
     */
    private function costLine(LicensableLibrary $library, Quote $quote, ?Account $account): string
    {
        if ($quote->cost === null) {
            return __(':library will charge this to your account; the cost isn\'t available before licensing.', ['library' => $library->label()]);
        }

        $product = $account?->product($quote->option) ?? $account?->product(explode(':', $quote->option)[0]);
        $remaining = $product['remaining'] ?? null;

        if ($remaining === null || $remaining->isMoney() || $quote->cost->isMoney()) {
            return __('Uses :cost.', ['cost' => $quote->cost->label()]);
        }

        $unit = $remaining->unit.($remaining->units === 1 ? '' : 's');
        $about = array_filter([$product['name'] ?? null, isset($product['resetsAt']) ? __('resets :date', ['date' => $product['resetsAt']->format('j M')]) : null]);

        return __('Uses :cost of your :remaining remaining :unit', ['cost' => $quote->cost->units, 'remaining' => $remaining->units, 'unit' => $unit]).($about ? ' ('.implode(', ', $about).')' : '').'.';
    }

    /**
     * Seat and storage notices from the licence terms (design Q13). They
     * inform; they don't block.
     *
     * @return array<int, string>
     */
    private function notices(LicensableLibrary $library, Quote $quote): array
    {
        $notices = [];

        if ($library->id() === 'istock' && $quote->productType === 'creditpack' && ! $quote->extended) {
            $notices[] = __('A standard iStock licence is for one person at a time and doesn\'t cover keeping the file on a shared server. Choose the extended licence if several editors will use it.');
        }

        if ($quote->productType === 'premiumaccess') {
            $notices[] = __('Images from Premium Access must be removed from shared storage if your agreement ends.');
        }

        return $notices;
    }
}

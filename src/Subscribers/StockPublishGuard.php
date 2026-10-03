<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Stock\StockLibraries;
use NineteenNinetyFour\Ghostwriter\Stock\UsageScanner;
use Statamic\Events\EntrySaving;
use Statamic\Facades\CP\Toast;

/**
 * A page can't go live holding a stock preview that isn't licensed
 * (design §7.1). Statamic fires EntrySaving on every save, publishing a
 * working copy and scheduled entries included, so: when the entry will be
 * published (now or on a future date) and its data holds a ledger asset in
 * `preview`, `licensing` or `failed`, the save is refused with a message on
 * that image field. Saving unpublished (a draft) always works.
 *
 * `ghostwriter.stock.on_publish` (or the settings screen) set to "warn"
 * lets it through with a warning instead.
 */
class StockPublishGuard
{
    public function __construct(private UsageScanner $scanner, private StockImages $images, private StockLibraries $libraries, private Settings $settings) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EntrySaving::class, [self::class, 'handle']);
    }

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! $entry->published()) {
            return;
        }

        $found = $this->scanner->scan($entry, $entry->values()->all());

        if ($found === []) {
            return;
        }

        $messages = [];

        foreach ($found as $usage) {
            $unlicensed = $this->images->unlicensedAmong([$usage['asset']]);

            if ($unlicensed === []) {
                continue;
            }

            $handle = explode('.', $usage['field'])[0];
            $messages[$handle][] = self::message($usage['label'] ?? $usage['field'], $this->libraries->shortLabel($unlicensed[0]->library));
        }

        if ($messages === []) {
            return;
        }

        if ($this->settings->stockOnPublish() === Settings::WARN) {
            Toast::error(implode(' ', array_merge(...array_values($messages))))->duration(12000);

            return;
        }

        throw ValidationException::withMessages(array_map(fn (array $lines) => array_values(array_unique($lines)), $messages));
    }

    /**
     * "The hero image is a Getty preview, not licensed yet. License it, or
     * choose another image, before publishing."
     */
    public static function message(string $field, string $library): string
    {
        return __('The :field is a :library preview, not licensed yet. License it, or choose another image, before publishing.', ['field' => Str::lcfirst($field), 'library' => $library]);
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Console\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use NineteenNinetyFour\Ghostwriter\Stock\UsageScanner;
use Statamic\Facades\Entry;

/**
 * Looks through every entry for the stock image ledger's assets and puts
 * right where each is used: for a site whose entries were changed outside
 * the Control Panel (by hand, or by a deploy). Saving an entry does this
 * for that entry by itself.
 */
class StockUsages extends Command
{
    protected $signature = 'ghostwriter:stock-usages';

    protected $description = 'Rescan every entry for Ghostwriter\'s stock images and update where each is used';

    public function handle(Ledger $ledger, UsageScanner $scanner): int
    {
        if (! $ledger->hasRecords()) {
            $this->info('The stock image ledger is empty: nothing to look for.');

            return self::SUCCESS;
        }

        $changed = 0;
        $entries = 0;

        foreach (Entry::all() as $entry) {
            $entries++;
            $changed += count($ledger->images()->syncUsages('entry', (string) $entry->id(), $entry->locale(), $scanner->scan($entry, $entry->data()->all()), (bool) $entry->published()));
        }

        $this->info("Looked through {$entries} ".($entries === 1 ? 'entry' : 'entries').": {$changed} stock image ".($changed === 1 ? 'record' : 'records').' updated.');

        return self::SUCCESS;
    }
}

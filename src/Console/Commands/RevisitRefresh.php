<?php

namespace NineteenNinetyFour\Ghostwriter\Console\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;

/**
 * Content to revisit's daily pass, on the scheduler: entries saved since
 * the last run and rows whose day has come (a closing date passed, a new
 * year), a full pass once a week, and Suggest edits' suggestions nobody
 * acted on for 14 days expired. No model, ever.
 */
class RevisitRefresh extends Command
{
    protected $signature = 'ghostwriter:revisit {--full : Read every published entry again} {--site= : One site\'s handle}';

    protected $description = 'Bring the Content to revisit list up to date (no AI), and expire old suggestions';

    public function handle(Revisit $revisit): int
    {
        $site = $this->option('site');
        $read = $revisit->daily(full: (bool) $this->option('full'), site: is_string($site) && $site !== '' ? $site : null);

        $this->info($read === 1 ? 'Content to revisit: 1 entry read.' : "Content to revisit: {$read} entries read.");

        return self::SUCCESS;
    }
}

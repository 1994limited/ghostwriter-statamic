<?php

namespace NineteenNinetyFour\Ghostwriter\Console\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ExternalLinkCheck;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Suggest\Revisit;

/**
 * The weekly check of links to other sites, on the scheduler. Off unless a
 * manager turns it on in the settings: with it off, no request is made.
 * Each address is asked at most once a week, one a second per site.
 */
class CheckLinks extends Command
{
    protected $signature = 'ghostwriter:check-links';

    protected $description = 'Check links to other sites (weekly; only when turned on in the settings)';

    public function handle(Revisit $revisit, ExternalLinkCheck $check, Settings $settings): int
    {
        if (! $settings->checksExternalLinks()) {
            $this->info('Checking links to other sites is off in Ghostwriter\'s settings.');

            return self::SUCCESS;
        }

        $checked = $revisit->links($check);
        $this->info($checked === 1 ? '1 link checked.' : "{$checked} links checked.");

        return self::SUCCESS;
    }
}

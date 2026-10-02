<?php

namespace NineteenNinetyFour\Ghostwriter\Subscribers;

use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\Ghostwriter\Stock\Ledger;
use Statamic\Events\AssetContainerBlueprintFound;
use Statamic\Facades\User;

/**
 * Adds the stock photo panel to the asset editor of an asset the stock
 * image ledger knows (one that carries `ghostwriter_stock`), for people
 * with Ghostwriter access.
 */
class AssetStockPanel
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(AssetContainerBlueprintFound::class, [self::class, 'handle']);
    }

    public function handle(AssetContainerBlueprintFound $event): void
    {
        if (! $event->asset || ! $event->asset->get(Ledger::MARKER) || ! User::current()?->can('access ghostwriter')) {
            return;
        }

        $event->blueprint->ensureFieldPrepended(Ledger::MARKER, [
            'type' => 'ghostwriter_stock',
            'display' => __('Stock photo'),
        ]);
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;

/**
 * "Demo stock (no charge)": core's FakeLibrary as a paid library a site
 * can try the whole preview and licence flow with, without an account
 * anywhere. It charges nothing and calls nobody; its pictures are drawn.
 *
 * It is only ever built where StockLibraries::demoAllowed() says: on a
 * local site, or where config turns it on, and never in production.
 */
class DemoLibrary
{
    public const ID = 'demo';

    /** The container key a test binds its own scripted demo library to. */
    public const BINDING = 'ghostwriter.stock.demo-library';

    /** Its photos: ID => title, width, height, and editorial restrictions if any. */
    public const PHOTOS = [
        'demo-101' => ['Stone path through a summer meadow', 1600, 1067, null],
        'demo-102' => ['Rain on a slate roof', 1600, 1067, null],
        'demo-109' => ['Crowd at a summer music festival', 1600, 1067, 'Editorial use only: news and commentary. No commercial, promotional or advertising use.'],
        'demo-103' => ['Beech hedge in autumn light', 1600, 1200, null],
        'demo-104' => ['Hands planting seedlings in a tray', 1600, 1067, null],
        'demo-105' => ['Harbour at low tide', 1600, 900, null],
        'demo-106' => ['Lighthouse in a winter storm', 1067, 1600, null],
        'demo-107' => ['Oak table in a bright kitchen', 1600, 1067, null],
        'demo-108' => ['Wild garlic in a beech wood', 1600, 1067, null],
        'demo-110' => ['City rooftops in morning fog', 1600, 1000, null],
    ];

    public static function make(): FakeLibrary
    {
        $library = new FakeLibrary(self::ID);

        foreach (self::PHOTOS as $id => [$title, $width, $height, $restrictions]) {
            $library->withPhotos(new Photo(
                self::ID, $id, cp_route('ghostwriter.stock.demo.thumb', $id), 'Demo photographer/Demo stock (no charge)', null, $restrictions ? 'Editorial' : 'Royalty-free',
                title: $title, width: $width, height: $height,
                offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD), $restrictions ? Offer::EDITORIAL : Offer::ROYALTY_FREE),
                editorial: $restrictions !== null, restrictions: $restrictions, collection: 'Demo collection',
            ));

            $library->withQuotes(
                $id,
                new Quote($id, 'demo-pack', 'Standard licence, 2,400 px', Cost::units(1, Cost::DOWNLOAD), 'demo', '2400'),
                new Quote($id, 'demo-pack:extended', 'Extended licence, 2,400 px (several people may use it)', Cost::units(3, Cost::DOWNLOAD), 'demo', '2400', extended: true),
            );
        }

        return $library;
    }
}

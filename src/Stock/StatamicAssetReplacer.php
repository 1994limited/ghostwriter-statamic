<?php

namespace NineteenNinetyFour\Ghostwriter\Stock;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetReplacer;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ReplaceMeta;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use RuntimeException;
use Statamic\Assets\ReplacementFile;
use Statamic\Facades\AssetContainer;

/**
 * "License & replace" on Statamic: the stand-in's file is swapped for the
 * licensed original with Asset::reupload(), which writes over the same
 * path, clears the caches, regenerates the meta and Glide's presets. The
 * asset's path, every reference to it, its title, alt text and focal
 * point stay as they are: they are the editor's by now.
 *
 * The bytes go in exactly as the library delivered them, never
 * re-encoded: the licences require the embedded copyright and IDs to stay.
 * So a file of another type than the stand-in (a JPEG) isn't converted;
 * the licence is kept, and the editor is asked to replace it by hand.
 *
 * The focal point is a percentage, and the stand-in had the photo's aspect
 * ratio, so it stays valid. Should the licensed file's aspect differ by
 * more than 1%, the focus is put back in the centre, and notice() says so.
 */
class StatamicAssetReplacer implements AssetReplacer
{
    private ?string $notice = null;

    public function __construct(private string $library = 'The library') {}

    public function replace(AssetRef $asset, PhotoFile $file, ReplaceMeta $meta): AssetRef
    {
        $found = AssetContainer::find($asset->volume)?->asset($asset->path)
            ?? throw new RuntimeException('The image is no longer in its asset container.');

        $extension = strtolower($file->extension) === 'jpeg' ? 'jpg' : strtolower($file->extension);

        if ($extension !== strtolower((string) $found->extension())) {
            throw new RuntimeException("{$this->library} sent a {$extension} file; replace the image by hand.");
        }

        $before = $found->width() && $found->height() ? $found->width() / $found->height() : null;

        $disk = config('statamic.system.file_uploads_disk', 'local');
        $temporary = 'ghostwriter-stock/'.Str::random(16).'/'.$found->basename();
        Storage::disk($disk)->put($temporary, $file->content);

        try {
            $found->reupload(new ReplacementFile($temporary));
        } finally {
            Storage::disk($disk)->deleteDirectory(dirname($temporary));
        }

        $found = AssetContainer::find($asset->volume)->asset($asset->path);
        $after = $found->width() && $found->height() ? $found->width() / $found->height() : null;

        if ($before !== null && $after !== null && abs($after - $before) / $before > 0.01 && $found->get('focus')) {
            $found->set('focus', '50-50-1');
            $this->notice = __('The licensed photo\'s shape differs from the preview, so its focal point was put back in the centre. Check it.');
        }

        $found->set('credit', $meta->creditLine ?? $found->get('credit'));
        $found->set('credit_url', $meta->creditUrl ?? $found->get('credit_url'));
        $found->set('licence', $meta->licenceType ? Str::headline($meta->licenceType) : $found->get('licence'));
        $found->set(Ledger::MARKER, $meta->ledgerId);
        $found->save();

        return $asset;
    }

    /** Anything the editor should check after the swap. */
    public function notice(): ?string
    {
        return $this->notice;
    }
}

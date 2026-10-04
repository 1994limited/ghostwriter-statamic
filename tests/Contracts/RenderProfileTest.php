<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Outline;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RenderProfileContract;
use NineteenNinetyFour\Ghostwriter\Storage\FileRenderProfiles;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;

/**
 * Core's RenderProfileContract on the JSON file the Preview tab's outlines
 * are kept in, recorded as PreviewController::outline() records them. The
 * seeded outline is what the Northfold test site's Pages preview posted.
 */
final class RenderProfileTest extends TestCase
{
    use RenderProfileContract;

    protected function profiles(): RenderProfiles
    {
        return app(FileRenderProfiles::class);
    }

    protected function record(string $key, array $outline): RenderProfile
    {
        return (new SeoPass(logger: Log::channel(config('ghostwriter.log_channel'))))->observe($this->profiles(), $key, Outline::fromArray($outline), 'Pages')[0];
    }

    protected function seededOutline(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/seeded-outline.json'), true);
    }

    public function test_a_collections_profile_is_found_for_another_site_when_its_own_has_none(): void
    {
        $this->record(FileRenderProfiles::key('pages', 'page', 'default'), $this->seededOutline());

        $this->assertSame('pages.page.default', app(FileRenderProfiles::class)->for('pages', 'page', 'german')?->key);
        $this->assertNull(app(FileRenderProfiles::class)->for('journal', 'post', 'default'));
    }
}

<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Statamic;

/**
 * "Use this draft" switches the form's Published toggle off on a new or
 * unpublished entry (the launcher does it in the browser); the Control
 * Panel is told whether to, from config.
 */
class DraftsUnpublishedTest extends TestCase
{
    public function test_drafts_start_unpublished_unless_the_config_says_not_to(): void
    {
        $this->signIn();

        $this->assertTrue($this->script()['drafts_unpublished']);

        config(['ghostwriter.drafts_unpublished' => false]);

        $this->assertFalse($this->script()['drafts_unpublished']);
    }

    public function test_the_launcher_switches_published_off_only_on_new_or_unpublished_entries(): void
    {
        $launcher = (string) file_get_contents(__DIR__.'/../../resources/js/components/Launcher.vue');

        // Never on an entry whose form says it is published: that would take it offline.
        $this->assertStringContainsString('if (this.entry && this.form.values?.published !== false) return false;', $launcher);
        $this->assertStringContainsString("this.form.setFieldValue('published', false);", $launcher);
        $this->assertStringContainsString("Ghostwriter drafts start unpublished. Switch on Published when you\\'re ready.", $launcher);
    }

    /**
     * @return array<string, mixed>
     */
    private function script(): array
    {
        return Statamic::jsonVariables(request())['ghostwriter'];
    }
}

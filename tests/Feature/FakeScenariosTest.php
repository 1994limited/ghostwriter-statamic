<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Testing\FakeScenarios;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use RuntimeException;

/**
 * The end-to-end tests' scripted replies: only on a local or testing app
 * with the folder set, only for a request or job that names a scenario,
 * and back to the real providers after each job.
 */
class FakeScenariosTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gw-fake-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/site', 0777, true);
        file_put_contents($this->dir.'/site/write.json', '{"agents":{"writer":[{"text":"First."},{"text":"Second."}]}}');
        app(Providers::class)->unfake();
        FakeScenarios::flush();
    }

    protected function tearDown(): void
    {
        FakeScenarios::flush();
        @unlink($this->dir.'/site/write.json');
        @rmdir($this->dir.'/site');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function request(array $headers): Request
    {
        $request = Request::create('/cp');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_it_is_off_unless_the_folder_is_set(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => null]);

        FakeScenarios::fromRequest(app(), $this->request(['X-Ghostwriter-Fake' => 'site/write']));

        $this->assertFalse(FakeScenarios::enabled(app()));
        $this->assertFalse(app(Providers::class)->faked());
    }

    public function test_it_is_never_on_in_production(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => $this->dir]);
        app()->detectEnvironment(fn () => 'production');

        FakeScenarios::fromRequest(app(), $this->request(['X-Ghostwriter-Fake' => 'site/write']));

        $this->assertFalse(FakeScenarios::enabled(app()));
        $this->assertFalse(app(Providers::class)->faked());
    }

    public function test_a_request_without_a_scenario_is_untouched(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => $this->dir]);

        FakeScenarios::fromRequest(app(), $this->request([]));

        $this->assertFalse(app(Providers::class)->faked());
    }

    public function test_the_header_or_cookie_plays_the_scenario(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => $this->dir]);

        FakeScenarios::fromRequest(app(), $this->request(['Cookie' => 'other=1; ghostwriter_fake=site%2Fwrite%23c1']));

        $this->assertSame('First.', app(Providers::class)->text()->text(new TextRequest('writer', '', ''))->text);
        $this->assertSame('Second.', app(Providers::class)->text()->text(new TextRequest('writer', '', ''))->text);
    }

    public function test_an_unknown_scenario_fails_rather_than_calling_a_model(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => $this->dir]);

        $this->expectException(RuntimeException::class);

        FakeScenarios::fromRequest(app(), $this->request(['X-Ghostwriter-Fake' => '../etc/passwd']));
    }

    public function test_a_queued_job_plays_the_scenario_it_was_queued_with_and_then_stops(): void
    {
        config(['ghostwriter.testing.fake_scenarios' => $this->dir]);
        FakeScenarios::register(app());

        $job = new SyncJob(app(), (string) json_encode(['job' => 'x', 'data' => [], FakeScenarios::PAYLOAD => 'site/write#j1']), 'sync', 'default');

        event(new JobProcessing('sync', $job));
        $this->assertTrue(app(Providers::class)->faked());
        $this->assertSame('First.', app(Providers::class)->text()->text(new TextRequest('writer', '', ''))->text);

        event(new JobProcessed('sync', $job));
        $this->assertFalse(app(Providers::class)->faked());
    }
}

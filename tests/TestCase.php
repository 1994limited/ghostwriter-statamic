<?php

namespace NineteenNinetyFour\Ghostwriter\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Ai\ConfigCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionStore;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\ServiceProvider;
use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Psr\Http\Message\RequestInterface;
use Ramsey\Uuid\Uuid;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use FakesRoles, PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $workspace;

    /** Stands in for every model: queue answers on it, and check what was sent. */
    protected FakeProvider $ai;

    /** Where a model call would go if one got past the fake. None should. */
    protected MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything the addon writes goes to a throwaway directory.
        $this->workspace = sys_get_temp_dir().'/ghostwriter-tests-'.bin2hex(random_bytes(4));

        config([
            'ghostwriter.voice.path' => $this->workspace.'/voice.md',
            'ghostwriter.sessions_path' => $this->workspace.'/sessions',
            'ghostwriter.stock_path' => $this->workspace.'/content/stock',
            'ghostwriter.queued_path' => $this->workspace.'/queued',
            'ghostwriter.plan.path' => $this->workspace.'/ideas.yaml',
            'ghostwriter.images.guide_path' => $this->workspace.'/imagery.md',
            'ghostwriter.types_path' => $this->workspace.'/types',
            'ghostwriter.collections' => [],
            'ghostwriter.provider' => 'anthropic',
            // Only the test's own keys, whatever is in the environment.
            'ghostwriter.keys' => ['anthropic' => 'test-key', 'openai' => null, 'gemini' => null],
            'ai.providers' => [],
            // Content to revisit's save hook, on only where a test is about it.
            'ghostwriter.revisit.on_save' => false,
        ]);

        // The addon's settings live in a file the test app keeps between
        // tests; each test starts with none saved.
        File::delete(resource_path('addons/ghostwriter-statamic.yaml'));

        $this->http = new MockHttpClient;
        $this->app->instance(HttpClients::class, $this->http);
        $this->app->forgetInstance(Providers::class);

        $this->ai = $this->app->make(Providers::class)->fake();
        $this->app->instance(FakeProvider::class, $this->ai);

        // Writes down what the layout algorithms gave, to compare two runs
        // (docs: core's layout.md).
        LayoutLog::start(getenv('GHOSTWRITER_RECORD_LAYOUTS') ?: null, static::class.'::'.$this->name());

        // The clock stands still for the whole test, so nothing turns on
        // whether a second ticks over between two steps (F9).
        Carbon::setTestNow(Carbon::now()->startOfSecond());

        // When recording requests or layouts, the clock, entry IDs and the
        // entries' file times (and so the order entries are listed in) are
        // the same on every run.
        if (getenv('GHOSTWRITER_RECORD_REQUESTS') || getenv('GHOSTWRITER_RECORD_LAYOUTS')) {
            Carbon::setTestNow('2026-01-01 12:00:00');
            Event::listen(EntrySaved::class, fn (EntrySaved $event) => is_file($path = (string) $event->entry->path()) && touch($path, Carbon::now()->getTimestamp()));
            $count = 0;
            Str::createUuidsUsing(function () use (&$count) {
                return Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$count));
            });
        }
    }

    /**
     * Take providers' keys away: "anthropic" stands for the writing key,
     * "openai" for the image key. The fake then answers as a site without
     * them would, and still records anything sent.
     */
    protected function withoutKeys(string ...$providers): void
    {
        foreach ($providers as $provider) {
            config(["ghostwriter.keys.{$provider}" => null]);
        }

        $this->ai->unconfigured(text: in_array('anthropic', $providers, true), image: in_array('openai', $providers, true));
    }

    /**
     * Stand in for the photo libraries, as Http::fake() would: each address
     * (host, path and query, without https://) is matched against the
     * patterns in turn. An array answers as JSON; a string is an image's
     * bytes. Anything unmatched gets a 404. Returns the client, whose
     * `requests` say what was asked for.
     *
     * @param  array<string, array<mixed>|string>  $routes
     */
    protected function photoLibrary(array $routes): MockHttpClient
    {
        $client = new MockHttpClient;

        $router = function (RequestInterface $request) use ($client, $routes) {
            $uri = $request->getUri();
            $address = $uri->getHost().$uri->getPath().($uri->getQuery() !== '' ? '?'.urldecode($uri->getQuery()) : '');

            foreach ($routes as $pattern => $answer) {
                if (Str::is($pattern, $address)) {
                    return is_array($answer)
                        ? $client->response(200, (string) json_encode($answer), ['content-type' => 'application/json'])
                        : $client->response(200, $answer, ['content-type' => 'image/png']);
                }
            }

            return $client->response(404, '{}', ['content-type' => 'application/json']);
        };

        $client->queue(...array_fill(0, 200, $router));

        $this->app->forgetInstance(StockSearch::class);
        $this->app->forgetInstance(PhotoFinder::class);
        $this->app->instance(StockSearch::class, new StockSearch($client, new ConfigCredentials, openverse: fn (): bool => app(Settings::class)->openverse()));

        return $client;
    }

    /**
     * The addresses the photo libraries were asked for, without https://.
     *
     * @return array<int, string>
     */
    protected function photoRequests(MockHttpClient $client): array
    {
        return array_map(fn (RequestInterface $request) => $request->getUri()->getHost().$request->getUri()->getPath().($request->getUri()->getQuery() !== '' ? '?'.urldecode($request->getUri()->getQuery()) : ''), $client->requests);
    }

    protected function tearDown(): void
    {
        // Writes down what was sent, to compare two runs (docs: core's studio.md).
        if ($path = getenv('GHOSTWRITER_RECORD_REQUESTS')) {
            RequestLog::append($path, static::class.'::'.$this->name(), $this->ai->requests());
        }

        if (getenv('GHOSTWRITER_RECORD_REQUESTS') || getenv('GHOSTWRITER_RECORD_LAYOUTS')) {
            Str::createUuidsNormally();
        }

        LayoutLog::stop();

        $this->assertSame([], $this->http->requests, 'A request reached the network layer.');

        File::deleteDirectory($this->workspace);
        File::delete(resource_path('addons/ghostwriter-statamic.yaml'));

        parent::tearDown();
    }

    /**
     * A page-builder collection: entries are assembled from replicator sets.
     */
    protected function makeArticlesCollection(): void
    {
        Collection::make('articles')->title('Articles')->save();

        Blueprint::make('article')->setNamespace('collections.articles')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'slug', 'field' => ['type' => 'slug']],
            ['handle' => 'summary', 'field' => ['type' => 'textarea', 'instructions' => 'Shown in lists.']],
            ['handle' => 'featured_image', 'field' => ['type' => 'video']],
            ['handle' => 'author', 'field' => ['type' => 'users', 'max_items' => 1]],
            ['handle' => 'page_builder', 'field' => ['type' => 'replicator', 'sets' => ['blocks' => ['display' => 'Blocks', 'sets' => [
                'hero' => ['display' => 'Hero', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'image', 'field' => ['type' => 'video']],
                ]],
                'long_form' => ['display' => 'Long Form', 'instructions' => 'Prose column.', 'fields' => [
                    ['handle' => 'content', 'field' => ['type' => 'bard', 'sets' => ['inline' => ['sets' => [
                        'pullquote' => ['display' => 'Pull Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
                    ]]]]],
                    ['handle' => 'numbered', 'field' => ['type' => 'toggle']],
                    ['handle' => 'width', 'field' => ['type' => 'button_group', 'options' => ['narrow' => 'Narrow', 'wide' => 'Wide']]],
                ]],
                'cards' => ['display' => 'Card Grid', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'items', 'field' => ['type' => 'grid', 'fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]]],
                ]],
                'related' => ['display' => 'Related', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'limit', 'field' => ['type' => 'integer']],
                ]],
                'gallery' => ['display' => 'Gallery', 'fields' => [
                    ['handle' => 'caption', 'field' => ['type' => 'text']],
                ]],
            ]]]]],
        ]])->save();
    }

    /**
     * A plain collection: one Bard body and nothing else, as many sites have.
     */
    protected function makePostsCollection(): void
    {
        Collection::make('posts')->title('Posts')->save();

        Blueprint::make('post')->setNamespace('collections.posts')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'intro', 'field' => ['type' => 'markdown']],
            ['handle' => 'content', 'field' => ['type' => 'bard']],
        ]])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeArticle(string $slug, string $title, string $paragraph, array $overrides = []): void
    {
        Entry::make()->collection('articles')->slug($slug)->published(true)->data($overrides + [
            'title' => $title,
            'summary' => 'A summary line about '.$title.' that is long enough to count.',
            'author' => 'user-1',
            'featured_image' => 'articles/'.$slug.'.jpg',
            'page_builder' => [
                ['id' => 'a1', 'type' => 'hero', 'enabled' => true],
                ['id' => 'a2', 'type' => 'long_form', 'enabled' => true, 'numbered' => true, 'width' => 'narrow', 'content' => [
                    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'The Problem']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $paragraph]]],
                    ['type' => 'set', 'attrs' => ['id' => 's1', 'values' => ['type' => 'pullquote', 'text' => 'Challenge accepted.']]],
                ]],
                ['id' => 'a3', 'type' => 'cards', 'enabled' => true, 'heading' => 'Broader uses', 'items' => [['id' => 'r1', 'text' => 'Property searches'], ['id' => 'r2', 'text' => 'Recipe selection']]],
                ['id' => 'a4', 'type' => 'related', 'enabled' => true, 'heading' => 'More articles', 'limit' => 3],
                ['id' => 'a5', 'type' => 'gallery', 'enabled' => false, 'caption' => 'Switched off'],
            ],
        ])->save();
    }

    protected function makeType(string $handle = 'articles', string $collection = 'articles'): ContentType
    {
        return app(TypeRepository::class)->save(TypeRepository::make($handle, [
            'title' => 'Article',
            'description' => 'A project write-up.',
            'collection' => $collection,
            'questions' => [
                ['handle' => 'what', 'label' => 'What was built?', 'type' => 'textarea', 'required' => true],
                ['handle' => 'avoid', 'label' => 'What must not appear?', 'type' => 'textarea'],
            ],
            'guidance' => 'Open on the reader. Two sections.',
            'checklist' => ['Every fact comes from the brief.'],
        ]));
    }

    /**
     * A new piece, not yet saved, as core starts one.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<int, string>  $examples
     */
    protected function makeSession(string $kind, array $answers = [], int|string|null $user = null, array $examples = []): Session
    {
        return Session::start(Format::Statamic, $kind, $answers, $user, $examples, Carbon::now()->toImmutable());
    }

    /**
     * Where sessions are kept.
     */
    protected function sessions(): SessionStore
    {
        return app(SessionStore::class);
    }

    /**
     * Run a queued job's handle() as the queue would, with what it asks for.
     */
    protected function runJob(object $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * Someone who may also change Ghostwriter's settings. A second person
     * signed in during a test needs Statamic Pro.
     */
    protected function signInAsManager(): \Statamic\Contracts\Auth\User
    {
        config(['statamic.editions.pro' => true]);

        return $this->signInWith(['access ghostwriter', 'edit 1994/ghostwriter-statamic settings', ...self::WRITER_PERMISSIONS]);
    }

    /** What a writer may do, Ghostwriter aside: the Statamic permissions the tests lean on. */
    protected const WRITER_PERMISSIONS = ['view suggest_pages entries', 'edit suggest_pages entries', 'view articles entries', 'edit articles entries', 'create articles entries', 'edit other authors articles entries', 'view pages entries', 'edit pages entries', 'create pages entries', 'view stories entries', 'edit stories entries', 'create stories entries', 'upload assets assets'];

    protected function signIn(bool $permitted = true): \Statamic\Contracts\Auth\User
    {
        return $this->signInWith($permitted ? ['access ghostwriter', ...self::WRITER_PERMISSIONS] : []);
    }

    /**
     * A user with the Control Panel and just these permissions.
     *
     * @param  array<int, string>  $permissions
     */
    protected function signInWith(array $permissions): \Statamic\Contracts\Auth\User
    {
        $this->setTestRoles(['tester' => ['access cp', ...$permissions]]);

        $user = User::make()->email('writer@example.com')->assignRole('tester');
        $user->save();

        $this->actingAs($user);

        return $user;
    }
}

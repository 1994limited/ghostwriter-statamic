<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuardOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PublishGuardContract;
use NineteenNinetyFour\Ghostwriter\Drafts\BardDialect;
use NineteenNinetyFour\Ghostwriter\Jobs\FindImages;
use NineteenNinetyFour\Ghostwriter\Stock\DemoLibrary;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Entry;

/**
 * Core's PublishGuardContract through Statamic's own save: EntrySaving,
 * the guard, ValidationException on the fields. The stock preview is a
 * real one, put in through the image dialog with the demo library.
 */
final class PublishGuardTest extends TestCase
{
    use PublishGuardContract;

    private ?string $preview = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ghostwriter.stock.demo' => true,
            'ghostwriter.images.openverse' => false,
            'statamic.editions.pro' => true,
            'filesystems.disks.assets' => ['driver' => 'local', 'root' => $this->workspace.'/assets', 'url' => '/assets'],
        ]);

        $this->app->instance(DemoLibrary::BINDING, DemoLibrary::make());

        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        foreach (['stories' => 'story', 'events' => 'event'] as $handle => $blueprint) {
            $dated = $handle === 'events';
            $collection = Collection::make($handle)->title(ucfirst($handle))->dated($dated);

            if ($dated) {
                $collection->futureDateBehavior('private');
            }

            $collection->save();

            Blueprint::make($blueprint)->setNamespace('collections.'.$handle)->setContents(['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'body', 'field' => ['type' => 'bard', 'display' => 'Body']],
                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1, 'display' => 'Image', 'folder' => 'covers']],
            ]])->save();
        }

        $this->signInWith(['access ghostwriter', 'license stock images', 'publish stories entries', ...self::WRITER_PERMISSIONS, 'view events entries', 'edit events entries', 'create events entries']);
        Entry::make()->id('host')->collection('stories')->slug('host')->published(false)->data(['title' => 'Host'])->save();
    }

    protected function guardMode(OnPublish $mode): void
    {
        config(['ghostwriter.publish.on_unfinished' => $mode->value]);
    }

    protected function guardEntry(string $text, bool $stockPreview = false, string $collection = 'stories'): mixed
    {
        $body = app(BardDialect::class)->fromMarkdown($text, Field::fromSpec(['handle' => 'body', 'type' => 'bard', 'kind' => 'richtext']));

        $entry = Entry::make()->collection($collection)->slug('page-'.bin2hex(random_bytes(3)))->published(false)->data(['title' => 'A page', 'body' => $body]);

        if ($stockPreview) {
            $entry->set('image', $this->stockPreview());
        }

        return $entry;
    }

    protected function guardPublish(mixed $entry): GuardOutcome
    {
        return $this->attempt(fn () => $entry->published(true)->save());
    }

    protected function guardSaveDraft(mixed $entry): GuardOutcome
    {
        return $this->attempt(fn () => $entry->published(false)->save());
    }

    protected function guardOtherWaysLive(mixed $entry): array
    {
        $scheduled = $this->guardEntry('Tickets cost [[ask: adult ticket price]].', collection: 'events');
        $scheduled->date(Carbon::now()->addWeek());

        $draft = $this->guardEntry('Tickets cost [[ask: adult ticket price]].');
        $draft->save();
        $outcomes = [
            'a scheduled entry' => $this->attempt(fn () => $scheduled->published(true)->save()),
            'the publish form' => $this->form($draft),
        ];

        // Revisions: a live page's working copy holding a fact to add.
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->workspace.'/revisions']);
        Collection::findByHandle('stories')->revisionsEnabled(true)->save();
        $live = $this->guardEntry('Tickets cost £12.');
        $live->published(true)->save();
        $copy = Entry::find($live->id());
        $copy->set('body', $this->guardEntry('Tickets cost [[ask: adult ticket price]].')->get('body'))->makeWorkingCopy()->save();

        return $outcomes + ['publishing a working copy' => $this->attempt(fn () => Entry::find($live->id())->publishWorkingCopy())];
    }

    public function test_the_message_names_the_field_and_the_fact_in_the_editors_words(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardPublish($this->guardEntry('Tickets cost [[ask: adult ticket price]] for adults.'));

        $this->assertSame(['body' => ['Add adult ticket price before publishing.']], $outcome->errors);
    }

    public function test_the_other_ways_live_are_refused_by_the_guard_itself(): void
    {
        $this->guardMode(OnPublish::Block);

        foreach ($this->guardOtherWaysLive(null) as $way => $outcome) {
            $this->assertSame(['body' => ['Add adult ticket price before publishing.']], $outcome->errors, $way);
        }
    }

    public function test_a_page_links_to_a_page_thats_gone_is_refused(): void
    {
        $this->guardMode(OnPublish::Block);
        $outcome = $this->guardPublish($this->guardEntry('Read [the old page](statamic://entry::gone) first.'));

        $this->assertFalse($outcome->saved);
        $this->assertStringContainsString('deleted', $outcome->errorText());
    }

    /**
     * Saved through the publish form's own update request, as the editor does.
     */
    private function form(EntryContract $entry): GuardOutcome
    {
        $values = $entry->blueprint()->fields()->addValues($entry->data()->all())->preProcess()->values()->all();
        $response = $this->patchJson($entry->updateUrl(), ['title' => 'A page', 'body' => $values['body'], 'published' => true, 'blueprint' => 'story', '_localized' => []]);

        return new GuardOutcome($response->status() < 300, $response->json('errors') ?? []);
    }

    private function attempt(callable $save): GuardOutcome
    {
        Toast::clear();

        try {
            $save();
        } catch (ValidationException $refused) {
            return new GuardOutcome(false, $refused->errors(), $this->warnings());
        }

        return new GuardOutcome(true, [], $this->warnings());
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        return array_values(array_map(fn ($toast) => $toast['message'], Toast::toArray()));
    }

    private function stockPreview(): string
    {
        if ($this->preview !== null) {
            return $this->preview;
        }

        Bus::fake([FindImages::class]);
        $started = $this->postJson(cp_route('ghostwriter.images.start'), ['collection' => 'stories', 'path' => 'image', 'entry' => 'host', 'title' => 'Host', 'mode' => 'find', 'words' => 'path', 'source' => 'demo'])->assertOk()->json();
        $this->runJob(new FindImages($started['id']));
        $stock = $this->postJson(cp_route('ghostwriter.images.use', $started['id']), ['source' => 'demo', 'photo' => 'demo-101', 'current' => []])->assertOk()->json('stock');

        return $this->preview = explode('::', $stock['asset'], 2)[1];
    }
}

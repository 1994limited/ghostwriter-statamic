<?php

namespace NineteenNinetyFour\Ghostwriter\Tests;

use Illuminate\Support\Facades\File;
use Laravel\Ai\AiServiceProvider;
use NineteenNinetyFour\Ghostwriter\ServiceProvider;
use NineteenNinetyFour\Ghostwriter\Types\ContentType;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Everything the addon writes goes to a throwaway directory.
        $this->workspace = sys_get_temp_dir().'/ghostwriter-tests-'.bin2hex(random_bytes(4));

        config([
            'ghostwriter.voice.path' => $this->workspace.'/voice.md',
            'ghostwriter.sessions_path' => $this->workspace.'/sessions',
            'ghostwriter.plan.path' => $this->workspace.'/ideas.yaml',
            'ghostwriter.images.guide_path' => $this->workspace.'/imagery.md',
            'ghostwriter.types_path' => $this->workspace.'/types',
            'ghostwriter.collections' => [],
            'ghostwriter.provider' => 'anthropic',
            'ai.providers.anthropic.key' => 'test-key',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
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
        return app(TypeRepository::class)->save(ContentType::fromArray($handle, [
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

    protected function signIn(bool $permitted = true): \Statamic\Contracts\Auth\User
    {
        $this->setTestRoles(['writer' => ['access cp', 'access ghostwriter', 'view articles entries', 'edit articles entries', 'edit other authors articles entries'], 'visitor' => ['access cp']]);

        $user = User::make()->email('writer@example.com')->assignRole($permitted ? 'writer' : 'visitor');
        $user->save();

        $this->actingAs($user);

        return $user;
    }
}

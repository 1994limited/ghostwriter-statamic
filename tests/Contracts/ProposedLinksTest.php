<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Blueprints\EntryLayouts;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\ProposedLinksContract;
use NineteenNinetyFour\Ghostwriter\Gaps\EntryGaps;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Core's ProposedLinksContract on Bard (SEO layer §12): a Bard body built
 * and saved as the publish form saves it, read by Finish this page's own
 * context (EntryGaps), gets a Link it step per Suggest links proposal with
 * Bard's `statamic://entry::id`, loses it once the words are linked, and
 * says when nothing was found.
 */
final class ProposedLinksTest extends TestCase
{
    use ProposedLinksContract;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('visits')->title('Visits')->routes('/visits/{slug}')->save();
        Blueprint::make('visit')->setNamespace('collections.visits')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'buttons' => ['h2', 'h3', 'bold', 'italic', 'anchor']]],
        ]])->save();
    }

    protected function finishContext(string $markdown, ?LinkProposals $proposals = null): GapContext
    {
        $blueprint = Collection::findByHandle('visits')->entryBlueprint();
        $schema = app(SchemaReader::class)->schema($blueprint);
        $built = app(EntryLayouts::class)->build(['title' => 'Winter visits', 'body' => $markdown], $schema)->data;
        $form = $blueprint->fields()->addValues($built)->preProcess()->values()->all();
        $saved = $blueprint->fields()->addValues($form)->process()->values()->all();

        return app(EntryGaps::class)->context($blueprint, $saved, 'visits', 'winter', proposals: $proposals);
    }

    protected function proposalPath(): FieldPath
    {
        return FieldPath::of('body');
    }

    protected function proposalHref(): string
    {
        return 'statamic://entry::contact';
    }
}

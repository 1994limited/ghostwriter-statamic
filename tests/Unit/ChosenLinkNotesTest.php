<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Unit;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Drafts\DraftValues;
use PHPUnit\Framework\TestCase;

/**
 * A link chosen from its chip for a field the draft doesn't hold: the
 * build's notes stop saying it is still to choose, and `gw_links` is never
 * built as a field.
 */
final class ChosenLinkNotesTest extends TestCase
{
    public function test_notes_lose_the_links_since_chosen(): void
    {
        $chosen = MarkerResolver::chosenLinks(MarkerResolver::chooseLink([], 'button link', 'entry::abc', '/contact'));
        $notes = [
            'Still to choose by hand: Hero: Image; Hero: Button link.',
            'Still to set by hand, as it differs from page to page: Hero (link still to choose).',
            'Ghostwriter drafts start unpublished.',
        ];

        $this->assertSame(['Still to choose by hand: Hero: Image.', 'Ghostwriter drafts start unpublished.'], DraftValues::withoutChosen($notes, $chosen, ['hero' => ['button_link' => 'entry::abc']]));

        // A link still to choose elsewhere keeps the house style's note.
        $this->assertSame($notes[1], DraftValues::withoutChosen($notes, $chosen, ['cta' => ['link' => '#gw-link:link']])[1]);
    }

    public function test_the_links_chosen_are_not_a_field_to_build(): void
    {
        $this->assertSame(['title' => 'Hi'], DraftValues::words(['title' => 'Hi', 'gw_links' => ['x' => ['link' => 'y']]]));
    }
}

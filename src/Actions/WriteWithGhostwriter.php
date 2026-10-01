<?php

namespace NineteenNinetyFour\Ghostwriter\Actions;

use NineteenNinetyFour\Ghostwriter\Settings;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;

/**
 * Adds "Write with Ghostwriter" to a collection's menu. It opens the usual
 * create screen with the Ghostwriter panel already open on it.
 */
class WriteWithGhostwriter extends Action
{
    protected $confirm = false;

    protected $icon = Settings::ICON;

    public static function title()
    {
        return __('Write with Ghostwriter');
    }

    public function visibleTo($item)
    {
        return $item instanceof Collection && app(TypeRepository::class)->enabled($item->handle());
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $user->can('access ghostwriter') && $user->can('create', [Entry::class, $item]);
    }

    public function redirect($items, $values)
    {
        return $items->first()->createEntryUrl().'?ghostwriter=new';
    }
}

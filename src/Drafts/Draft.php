<?php

namespace NineteenNinetyFour\Ghostwriter\Drafts;

use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/*
 * Drafts are parsed by Ghostwriter Core now. This keeps the old name
 * working for projects with their own EntryWriter, whose write() method
 * names it.
 *
 * @deprecated Use NineteenNinetyFour\Ghostwriter\Core\Text\Draft. Goes in 2.0.
 */
class_alias(Draft::class, __NAMESPACE__.'\Draft');

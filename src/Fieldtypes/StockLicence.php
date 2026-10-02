<?php

namespace NineteenNinetyFour\Ghostwriter\Fieldtypes;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImages;
use NineteenNinetyFour\Ghostwriter\Stock\StockPresenter;
use Statamic\Fields\Fieldtype;
use Throwable;

/**
 * The stock photo panel in the asset editor (design §7.2): the asset's
 * ledger record, its state, library, ID and licence, and a License button.
 * Read-only: its value is the ledger record's ID in the asset's data
 * (`ghostwriter_stock`), saved back as it was.
 */
class StockLicence extends Fieldtype
{
    protected static $handle = 'ghostwriter_stock';

    protected $selectable = false;

    public function preload()
    {
        $id = $this->field->value();

        try {
            $image = is_string($id) && $id !== '' ? app(StockImages::class)->find($id) : null;
        } catch (Throwable) {
            $image = null;
        }

        return ['record' => $image ? app(StockPresenter::class)->summary($image) : null];
    }

    public function process($data)
    {
        return $this->field->value() ?? $data;
    }
}

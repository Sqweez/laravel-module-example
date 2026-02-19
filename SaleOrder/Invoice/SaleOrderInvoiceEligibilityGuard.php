<?php

namespace App\Service\SaleOrder\Invoice;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;

class SaleOrderInvoiceEligibilityGuard
{
    public function validate(SaleOrder $saleOrder): void
    {
        if ($saleOrder->status_id !== SaleOrderStatus::OPEN) {
            throw new UnprocessableEntityException(
                sprintf(
                    'Cannot generate invoice for sale order with status "%s". Sale order must be Open.',
                    $saleOrder->status_id->label()
                )
            );
        }

        if ($saleOrder->items->isEmpty()) {
            $message = 'Cannot generate invoice for sale order without line items';

            throw UnprocessableEntityException::forField('items', $message);
        }
    }
}

<?php

namespace App\Service\SaleOrder\Invoice;

use App\Models\SaleOrder\Enums\SaleOrderInvoicePaymentStatus;

final class SaleOrderInvoicePaymentStatusResolver
{
    public static function unpaid(): SaleOrderInvoicePaymentStatus
    {
        return SaleOrderInvoicePaymentStatus::UNPAID;
    }
}

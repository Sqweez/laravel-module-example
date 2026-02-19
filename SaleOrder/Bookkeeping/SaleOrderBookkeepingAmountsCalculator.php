<?php

namespace App\Service\SaleOrder\Bookkeeping;

use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderPayment;

class SaleOrderBookkeepingAmountsCalculator
{
    public function calculateRefundedCents(SaleOrder $saleOrder, bool $includeCompletedRefunds): int
    {
        $query = $saleOrder->payments()->where('amount_cents', '<', 0);

        if (! $includeCompletedRefunds && $saleOrder->status_id !== SaleOrderStatus::COMPLETED) {
            $query->where('created_at', '<=', now());
        }

        return (int) abs((int) $query->sum('amount_cents'));
    }

    public function refundedBeforeCompletion(SaleOrder $saleOrder): int
    {
        if (! $saleOrder->date_completed) {
            return 0;
        }

        $completedAt = $saleOrder->date_completed->copy()->endOfDay();

        return (int) abs((int) SaleOrderPayment::query()
            ->where('sale_order_id', $saleOrder->id)
            ->where('amount_cents', '<', 0)
            ->where('created_at', '<=', $completedAt)
            ->sum('amount_cents'));
    }
}

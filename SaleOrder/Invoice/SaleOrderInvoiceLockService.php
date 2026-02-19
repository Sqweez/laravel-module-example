<?php

namespace App\Service\SaleOrder\Invoice;

use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\User;
use RuntimeException;

/**
 * Encapsulates locking strategies for invoice operations.
 *
 * Lock order (to prevent deadlocks): sale_orders → sale_order_invoices
 */
class SaleOrderInvoiceLockService
{
    private const string NOT_FOUND_MESSAGE = 'Invoice not found or access denied';

    /**
     * Lock the invoice row for header updates.
     *
     * Used by: updateInvoice
     * Locks only the invoice since updates don't affect sale order or other invoices.
     */
    public function lockInvoiceForUpdate(int $invoiceId, User $user): SaleOrderInvoice
    {
        $invoice = SaleOrderInvoice::where('id', $invoiceId)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->first();

        if (!$invoice) {
            throw new RuntimeException(self::NOT_FOUND_MESSAGE, 404);
        }

        return $invoice;
    }

    /**
     * Lock sale order and eager-load items for invoice generation/creation.
     *
     * Used by: generateFromSaleOrder, createInvoice
     * Locks sale order to serialize with cancel/archival operations and prevent race conditions
     * when multiple invoices are created concurrently. Items are eager-loaded (not locked).
     */
    public function lockSaleOrderWithItems(int $saleOrderId, User $user): SaleOrder
    {
        $saleOrder = SaleOrder::where('id', $saleOrderId)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->with('items')
            ->first();

        if (!$saleOrder) {
            throw new RuntimeException('Sale order not found or access denied', 404);
        }

        return $saleOrder;
    }

    /**
     * Lock sale order first, then invoice, for archive operations.
     *
     * Used by: archiveInvoice
     * Lock order matters: sale_order before invoice to serialize with other operations
     * (cancel, other archives) on the same order and prevent deadlocks.
     *
     * @return array{SaleOrder, SaleOrderInvoice}
     */
    public function lockSaleOrderThenInvoice(int $invoiceId, User $user): array
    {
        $storeId = $user->store->id;

        $saleOrderId = SaleOrderInvoice::where('id', $invoiceId)->where('store_id', $storeId)->value('sale_order_id');

        if (!$saleOrderId) {
            throw new RuntimeException(self::NOT_FOUND_MESSAGE, 404);
        }

        $saleOrder = SaleOrder::where('id', $saleOrderId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first() ?? throw new RuntimeException('Sale order not found or access denied', 404);

        $invoice = SaleOrderInvoice::where('id', $invoiceId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first() ?? throw new RuntimeException(self::NOT_FOUND_MESSAGE, 404);

        return [$saleOrder, $invoice];
    }
}

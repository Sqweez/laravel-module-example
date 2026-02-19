<?php

namespace App\Service\SaleOrder\Invoice;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Models\SaleOrder\SaleOrderInvoiceItem;
use App\Models\SaleOrder\SaleOrderItem;
use Illuminate\Support\Collection;

readonly class SaleOrderInvoiceItemAllocationService
{
    public function normalizeRequestedQuantities(iterable $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $itemId = (int) $item->sale_order_item_id;
            $quantity = (int) $item->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $quantities[$itemId] = ($quantities[$itemId] ?? 0) + $quantity;
        }

        return $quantities;
    }

    public function assertItemsPresent(array $requestedQuantities): void
    {
        if ($requestedQuantities === []) {
            $message = 'Invoice must include at least one item.';

            throw UnprocessableEntityException::forField('items', $message);
        }
    }

    public function lockSaleOrderItems(SaleOrder $saleOrder, array $itemIds): Collection
    {
        $items = SaleOrderItem::where('sale_order_id', $saleOrder->id)
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($items->count() !== count($itemIds)) {
            $message = 'One or more selected items do not belong to this sale order.';

            throw UnprocessableEntityException::forField('items', $message);
        }

        return $items;
    }

    public function getInvoicedQuantities(SaleOrder $saleOrder, array $itemIds): array
    {
        return SaleOrderInvoiceItem::query()
            ->select('sale_order_invoice_items.sale_order_item_id')
            ->selectRaw('SUM(sale_order_invoice_items.quantity) as invoiced_quantity')
            ->join(
                'sale_order_invoices',
                'sale_order_invoices.id',
                '=',
                'sale_order_invoice_items.sale_order_invoice_id'
            )
            ->whereNull('sale_order_invoice_items.deleted_at')
            ->whereNull('sale_order_invoices.deleted_at')
            ->where('sale_order_invoices.status_id', '!=', SaleOrderInvoiceStatus::ARCHIVED)
            ->where('sale_order_invoices.sale_order_id', $saleOrder->id)
            ->whereIn('sale_order_invoice_items.sale_order_item_id', $itemIds)
            ->groupBy('sale_order_invoice_items.sale_order_item_id')
            ->pluck('invoiced_quantity', 'sale_order_invoice_items.sale_order_item_id')
            ->map(static fn ($value) => (float) $value)
            ->all();
    }

    public function validateQuantities(
        array $requestedQuantities,
        Collection $saleOrderItems,
        array $invoicedQuantities
    ): void {
        foreach ($requestedQuantities as $itemId => $requestedQuantity) {
            $orderItem = $saleOrderItems->get($itemId);
            $orderedQuantity = (float) $orderItem->quantity;
            $alreadyInvoiced = $invoicedQuantities[$itemId] ?? 0.0;
            $availableQuantity = $orderedQuantity - $alreadyInvoiced;

            if ($availableQuantity <= 0) {
                $message = "Sale order item {$itemId} has no remaining quantity to invoice.";

                throw UnprocessableEntityException::forField('items', $message);
            }

            if ($requestedQuantity > $availableQuantity) {
                $message = "Sale order item {$itemId} exceeds available quantity ({$availableQuantity}).";

                throw UnprocessableEntityException::forField('items', $message);
            }
        }
    }

    public function createInvoiceItems(
        SaleOrderInvoice $invoice,
        Collection $saleOrderItems,
        array $requestedQuantities
    ): void {
        foreach ($requestedQuantities as $itemId => $requestedQuantity) {
            $saleOrderItem = $saleOrderItems->get($itemId);

            $invoiceItem = new SaleOrderInvoiceItem([
                'sale_order_invoice_id' => $invoice->id,
                'sale_order_item_id' => $saleOrderItem->id,
                'variant_id' => $saleOrderItem->variant_id,
                'line_no' => $saleOrderItem->line_no,
                'product_code' => $saleOrderItem->product_code,
                'supplier_code' => $saleOrderItem->supplier_code,
                'product_description' => $saleOrderItem->product_description,
                'unit_of_measure' => $saleOrderItem->unit_of_measure,
                'quantity' => $requestedQuantity,
                'unit_price_cents' => $saleOrderItem->unit_price_cents,
                'discount_percent' => $saleOrderItem->discount_percent,
                'total_cents' => 0
            ]);

            $invoiceItem->calculateTotal();
            $invoiceItem->save();
        }
    }

    public function copyItemsFromSaleOrder(SaleOrderInvoice $invoice, SaleOrder $saleOrder): void
    {
        foreach ($saleOrder->items as $item) {
            SaleOrderInvoiceItem::create([
                'sale_order_invoice_id' => $invoice->id,
                'sale_order_item_id' => $item->id,
                'variant_id' => $item->variant_id,
                'line_no' => $item->line_no,
                'product_code' => $item->product_code,
                'supplier_code' => $item->supplier_code,
                'product_description' => $item->product_description,
                'unit_of_measure' => $item->unit_of_measure,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'discount_percent' => $item->discount_percent,
                'total_cents' => $item->total_cents
            ]);
        }
    }
}

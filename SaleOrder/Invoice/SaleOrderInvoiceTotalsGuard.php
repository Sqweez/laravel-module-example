<?php

namespace App\Service\SaleOrder\Invoice;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;

/**
 * Validates that discount does not exceed subtotal plus shipping.
 *
 * Note: Intentionally duplicates validation from SaleOrderTotalsCalculator to keep
 * the invoice domain self-contained and avoid cross-domain coupling per refactor plan.
 */
class SaleOrderInvoiceTotalsGuard
{
    private const string DISCOUNT_EXCEEDS_MESSAGE = 'Discount cannot exceed subtotal plus shipping';

    /**
     * Validate sale order totals before snapshotting to invoice.
     */
    public function validateSaleOrder(SaleOrder $saleOrder): void
    {
        $subtotalCents = $this->computeSubtotal($saleOrder);
        $this->assertNonNegativeTotal($subtotalCents, $saleOrder->shipping_cents, $saleOrder->discount_cents);
    }

    /**
     * Validate invoice totals before calculating/persisting.
     */
    public function validateInvoice(SaleOrderInvoice $invoice): void
    {
        $subtotalCents = $this->computeInvoiceSubtotal($invoice);
        $this->assertNonNegativeTotal($subtotalCents, $invoice->shipping_cents, $invoice->discount_cents);
    }

    /**
     * Validate pre-computed total (for update scenarios where values come from payload).
     */
    public function validateTotal(int $totalCents): void
    {
        if ($totalCents < 0) {
            throw UnprocessableEntityException::forField('discount', self::DISCOUNT_EXCEEDS_MESSAGE);
        }
    }

    private function computeSubtotal(SaleOrder $saleOrder): int
    {
        return $saleOrder->relationLoaded('items')
            ? $saleOrder->items->sum('total_cents')
            : $saleOrder->items()->sum('total_cents');
    }

    private function computeInvoiceSubtotal(SaleOrderInvoice $invoice): int
    {
        return $invoice->relationLoaded('items')
            ? $invoice->items->sum('total_cents')
            : $invoice->items()->sum('total_cents');
    }

    private function assertNonNegativeTotal(int $subtotalCents, int $shippingCents, int $discountCents): void
    {
        $total = $subtotalCents + $shippingCents - $discountCents;
        $this->validateTotal($total);
    }
}

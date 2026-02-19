<?php

namespace App\Service\SaleOrder\Invoice;

use App\Models\SaleOrder\Enums\SaleOrderInvoicePaymentStatus;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\User;

readonly class SaleOrderInvoiceNumberingService
{
    public function getNextSequenceNumber(SaleOrder $saleOrder): int
    {
        return ($saleOrder->invoices()->withTrashed()->max('sequence_no') ?? 0) + 1;
    }

    public function formatInvoiceNumber(string $saleOrderNo, int $sequenceNo): string
    {
        return sprintf('%s-INV-%02d', $saleOrderNo, $sequenceNo);
    }

    public function createInvoiceHeader(
        SaleOrder $saleOrder,
        User $user,
        string $invoiceNo,
        int $sequenceNo,
        ?string $customerName = null,
        ?string $customerAddress = null
    ): SaleOrderInvoice {
        return SaleOrderInvoice::create([
            'user_id' => $user->id,
            'store_id' => $saleOrder->store_id,
            'sale_order_id' => $saleOrder->id,
            'sale_order_invoice_no' => $invoiceNo,
            'sequence_no' => $sequenceNo,
            'customer_name' => $customerName ?? $saleOrder->customer_name,
            'customer_address' => $customerAddress ?? $saleOrder->customer_address,
            'payment_terms_type' => $saleOrder->payment_terms_type,
            'payment_terms_days' => $saleOrder->payment_terms_days,
            'date_created' => now()->timezone($user->retrieveTimezone())->toDateString(),
            'status_id' => SaleOrderInvoiceStatus::ACTIVE,
            'payment_status' => SaleOrderInvoicePaymentStatus::UNPAID,
            'shipping_cents' => $saleOrder->shipping_cents,
            'discount_cents' => $saleOrder->discount_cents,
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'notes' => $saleOrder->notes
        ]);
    }
}

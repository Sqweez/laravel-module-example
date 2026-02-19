<?php

namespace App\Service\SaleOrder\Payment;

use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

readonly class SaleOrderInvoicePaymentValidator
{
    public const string ALL_INVOICES_PAID_MESSAGE = 'All invoices for this sale order are already paid; no additional payments can be recorded.';

    public function validatePaymentAmount(SaleOrderInvoice $invoice, int $newAmount, ?SaleOrder $saleOrder = null): array
    {
        if ($saleOrder === null) {
            $invoice->loadMissing('saleOrder');
            $saleOrder = $invoice->saleOrder;
        }

        if ($newAmount > 0) {
            $paidError = $this->validateAllActiveInvoicesPaid($saleOrder);
            if ($paidError) {
                return [$paidError];
            }
        }

        $payments = $invoice->payments()
            ->where('sale_order_id', $saleOrder->id)
            ->get();
        $totals = $this->calculatePaymentTotals($payments, $newAmount);

        if ($refundError = $this->validateRefundLimit($totals)) {
            return [$refundError];
        }

        if ($totalError = $this->validateOrderTotal($totals, $invoice->total_cents)) {
            return [$totalError];
        }

        return [];
    }

    public function validateAllActiveInvoicesPaid(SaleOrder $saleOrder): ?string
    {
        if (! $this->allActiveInvoicesPaid($saleOrder)) {
            return null;
        }

        return self::ALL_INVOICES_PAID_MESSAGE;
    }

    public function calculatePaymentTotals(Collection $payments, int $newAmount): array
    {
        $existingPaid = $this->calculatePaidAmount($payments);
        $existingRefunded = $this->calculateRefundedAmount($payments);

        return [
            'existingPaid' => $existingPaid,
            'existingRefunded' => $existingRefunded,
            'newPaid' => $existingPaid + max($newAmount, 0),
            'newRefunded' => $existingRefunded + max(-$newAmount, 0),
            'newNet' => $existingPaid + max($newAmount, 0) - ($existingRefunded + max(-$newAmount, 0))
        ];
    }

    private function allActiveInvoicesPaid(SaleOrder $saleOrder): bool
    {
        $invoiceTotals = SaleOrderInvoice::query()
            ->where('sale_order_invoices.sale_order_id', $saleOrder->id)
            ->where('sale_order_invoices.status_id', SaleOrderInvoiceStatus::ACTIVE)
            ->leftJoin('sale_order_payments as sop', function ($join) {
                $join->on('sale_order_invoices.id', '=', 'sop.sale_order_invoice_id')
                    ->whereNull('sop.deleted_at')
                    ->whereColumn('sop.sale_order_id', 'sale_order_invoices.sale_order_id');
            })
            ->selectRaw('sale_order_invoices.id, sale_order_invoices.total_cents, COALESCE(SUM(sop.amount_cents), 0) as net_paid')
            ->groupBy('sale_order_invoices.id', 'sale_order_invoices.total_cents');

        $summary = DB::query()
            ->fromSub($invoiceTotals, 'invoice_totals')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('SUM(CASE WHEN net_paid >= total_cents THEN 1 ELSE 0 END) as paid_count')
            ->first();

        if (! $summary || (int) $summary->invoice_count === 0) {
            return false;
        }

        return (int) $summary->invoice_count === (int) $summary->paid_count;
    }


    private function validateRefundLimit(array $totals): ?string
    {
        if ($totals['newNet'] >= 0) {
            return null;
        }

        return "Cannot refund more than paid. Paid: {$totals['newPaid']}, Refunded after this: {$totals['newRefunded']}";
    }

    private function validateOrderTotal(array $totals, int $orderTotal): ?string
    {
        if ($totals['newNet'] <= $orderTotal) {
            return null;
        }

        $newNet = Money::fromCents($totals['newNet']);
        return (
            "Net payments ({$newNet->format()}) cannot exceed order total ("
            . Money::fromCents($orderTotal)->format()
            . ')'
        );
    }

    private function calculatePaidAmount(Collection $payments): int
    {
        return $payments->sum(fn ($p) => max($p->amount_cents, 0));
    }

    private function calculateRefundedAmount(Collection $payments): int
    {
        return $payments->sum(fn ($p) => max(-$p->amount_cents, 0));
    }
}

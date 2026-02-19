<?php

namespace App\Service\SaleOrder;

use App\Models\SaleOrder\Enums\SaleOrderInvoicePaymentStatus;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use Illuminate\Support\Facades\DB;

final readonly class SaleOrderAbilityService
{
    public function collect(SaleOrder $saleOrder): array
    {
        $canCreatePayments = $this->canCreatePayments($saleOrder);

        return [
            'can_be_completed' => $this->canBeCompleted($saleOrder),
            'can_create_shipments' => $this->canCreateShipments($saleOrder),
            'can_create_invoices' => $this->canGenerateInvoices($saleOrder),
            'can_delete_payments' => $this->canDeletePayments($saleOrder),
            'can_create_payments' => $canCreatePayments,
            'can_create_positive_payments' => $canCreatePayments && $this->canCreatePositivePayments($saleOrder),
            'can_create_refunds' => $canCreatePayments && $this->canCreateRefunds($saleOrder),
        ];
    }

    private function canCreatePayments(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->status_id === SaleOrderStatus::DRAFT) {
            return false;
        }

        return $this->hasInvoices($saleOrder);
    }

    private function canDeletePayments(SaleOrder $saleOrder): bool
    {
        return $saleOrder->status_id->isInteractive();
    }

    private function canGenerateInvoices(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->status_id === SaleOrderStatus::DRAFT) {
            return false;
        }
        if (! $saleOrder->status_id->isInteractive()) {
            return false;
        }

        return $saleOrder->availableInvoiceItems()->exists();
    }

    private function canBeCompleted(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->status_id !== SaleOrderStatus::SHIPPED) {
            return false;
        }

        $invoices = $saleOrder->relationLoaded('invoices') ? $saleOrder->invoices : $saleOrder->invoices()->get();

        $activeInvoices = $invoices->filter(
            static fn (SaleOrderInvoice $invoice) => $invoice->status_id === SaleOrderInvoiceStatus::ACTIVE
        );

        if ($activeInvoices->isEmpty()) {
            return false;
        }

        return $activeInvoices->every(
            static fn (SaleOrderInvoice $invoice) => $invoice->payment_status === SaleOrderInvoicePaymentStatus::PAID
        );
    }

    private function canCreateShipments(SaleOrder $saleOrder): bool
    {
        if (
            $saleOrder->status_id !== SaleOrderStatus::OPEN
            && $saleOrder->status_id !== SaleOrderStatus::PARTIALLY_SHIPPED
        ) {
            return false;
        }

        if ($saleOrder->relationLoaded('invoices')) {
            return $saleOrder->invoices->contains(
                static fn (SaleOrderInvoice $invoice) => $invoice->status_id === SaleOrderInvoiceStatus::ACTIVE
            );
        }

        return $saleOrder->invoices()->where('status_id', SaleOrderInvoiceStatus::ACTIVE)->exists();
    }

    private function canCreatePositivePayments(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->status_id === SaleOrderStatus::DRAFT) {
            return false;
        }

        $summary = $this->activeInvoicePaymentSummary($saleOrder);

        if ($summary['invoice_count'] === 0) {
            return false;
        }

        return $summary['invoice_count'] !== $summary['paid_count'];
    }

    private function canCreateRefunds(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->status_id === SaleOrderStatus::DRAFT) {
            return false;
        }

        return $this->hasRefundableInvoices($saleOrder);
    }

    private function hasInvoices(SaleOrder $saleOrder): bool
    {
        if ($saleOrder->relationLoaded('invoices')) {
            return $saleOrder->invoices->isNotEmpty();
        }

        return $saleOrder->invoices()->exists();
    }

    /**
     * @return array{invoice_count: int, paid_count: int}
     */
    private function activeInvoicePaymentSummary(SaleOrder $saleOrder): array
    {
        $invoiceTotals = SaleOrderInvoice::query()
            ->where('sale_order_invoices.sale_order_id', $saleOrder->id)
            ->where('sale_order_invoices.status_id', SaleOrderInvoiceStatus::ACTIVE)
            ->leftJoin('sale_order_payments as sop', function ($join) {
                $join->on('sale_order_invoices.id', '=', 'sop.sale_order_invoice_id')
                    ->whereNull('sop.deleted_at')
                    ->whereColumn('sop.sale_order_id', 'sale_order_invoices.sale_order_id');
            })
            ->selectRaw(
                'sale_order_invoices.id, sale_order_invoices.total_cents, '
                . 'COALESCE(SUM(sop.amount_cents), 0) as net_paid'
            )
            ->groupBy('sale_order_invoices.id', 'sale_order_invoices.total_cents');

        $summary = DB::query()
            ->fromSub($invoiceTotals, 'invoice_totals')
            ->selectRaw('COUNT(*) as invoice_count')
            ->selectRaw('SUM(CASE WHEN net_paid >= total_cents THEN 1 ELSE 0 END) as paid_count')
            ->first();

        return [
            'invoice_count' => (int) ($summary->invoice_count ?? 0),
            'paid_count' => (int) ($summary->paid_count ?? 0),
        ];
    }

    private function hasRefundableInvoices(SaleOrder $saleOrder): bool
    {
        return SaleOrderInvoice::query()
            ->where('sale_order_invoices.sale_order_id', $saleOrder->id)
            ->leftJoin('sale_order_payments as sop', function ($join) {
                $join->on('sale_order_invoices.id', '=', 'sop.sale_order_invoice_id')
                    ->whereNull('sop.deleted_at')
                    ->whereColumn('sop.sale_order_id', 'sale_order_invoices.sale_order_id');
            })
            ->select('sale_order_invoices.id')
            ->groupBy('sale_order_invoices.id')
            ->havingRaw('COALESCE(SUM(sop.amount_cents), 0) > 0')
            ->exists();
    }
}

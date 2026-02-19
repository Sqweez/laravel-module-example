<?php

namespace App\Service\SaleOrder\Invoice;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\User;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class SaleOrderInvoiceArchiveService
{
    public function __construct(
        private SaleOrderInvoiceLockService $lockService
    ) {
    }

    /**
     * @throws Throwable
     */
    public function archive(int $id, User $user): SaleOrderInvoice
    {
        return DB::transaction(function () use ($id, $user) {
            [$saleOrder, $invoice] = $this->lockService->lockSaleOrderThenInvoice($id, $user);
            $this->ensureInvoiceIsActive($invoice);
            $this->ensureNotLastActiveInvoice($invoice);

            $invoice->update([
                'status_id' => SaleOrderInvoiceStatus::ARCHIVED,
                'payment_status' => $invoice->computeCurrentPaymentStatus()
            ]);

            return $invoice->fresh(['items', 'saleOrder.payments']);
        });
    }

    private function ensureInvoiceIsActive(SaleOrderInvoice $invoice): void
    {
        if ($invoice->status_id !== SaleOrderInvoiceStatus::ACTIVE) {
            throw new UnprocessableEntityException('Cannot archive invoice: invoice is not active');
        }
    }

    private function ensureNotLastActiveInvoice(SaleOrderInvoice $invoice): void
    {
        $activeCount = SaleOrderInvoice::where('sale_order_id', $invoice->sale_order_id)
            ->where('status_id', SaleOrderInvoiceStatus::ACTIVE)
            ->count();

        if ($activeCount === 1) {
            throw new UnprocessableEntityException(
                'Cannot archive the last active invoice for a sale order. Shipments require an active invoice.'
            );
        }
    }
}

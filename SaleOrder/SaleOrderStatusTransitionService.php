<?php

namespace App\Service\SaleOrder;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoicePaymentStatus;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\Enums\SaleOrderShipmentStatus;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Service\SaleOrder\Bookkeeping\SaleOrderBookkeepingService;
use App\Service\SaleOrder\Bookkeeping\SaleOrderBookkeepingValidationService;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

readonly class SaleOrderStatusTransitionService
{
    public function __construct(
        private SaleOrderBookkeepingService $saleOrderBookkeepingService,
        private SaleOrderBookkeepingValidationService $saleOrderBookkeepingValidationService
    ) {
    }

    /**
     * @throws Throwable
     */
    public function transition(int $id, SaleOrderStatus $toStatus, User $user): SaleOrder
    {
        return DB::transaction(function () use ($id, $toStatus, $user) {
            $saleOrder = $this->lockAndLoadSaleOrder($id, $user);

            $this->validateTransition($saleOrder, $toStatus);

            $fromStatus = $saleOrder->status_id;

            if ($toStatus === SaleOrderStatus::CANCELLED) {
                $this->archiveActiveInvoices($saleOrder);
                $this->archiveActiveShipments($saleOrder);
            }

            $saleOrder->status_id = $toStatus;

            if ($toStatus === SaleOrderStatus::COMPLETED) {
                $saleOrder->date_completed = now()->timezone($user->retrieveTimezone())->toDateString();
            }

            $saleOrder->save();

            if ($fromStatus === SaleOrderStatus::DRAFT && $toStatus === SaleOrderStatus::OPEN) {
                $this->saleOrderBookkeepingService->recordOpen($saleOrder);
            }

            if ($toStatus === SaleOrderStatus::COMPLETED) {
                $this->saleOrderBookkeepingService->recordCompleted($saleOrder);
                $this->saleOrderBookkeepingValidationService->validateAndMarkException($saleOrder);
            }

            $this->logStatusTransition($saleOrder, $fromStatus, $toStatus);

            return $saleOrder->fresh(['items']);
        });
    }

    private function lockAndLoadSaleOrder(int $id, User $user): SaleOrder
    {
        $saleOrder = SaleOrder::where('id', $id)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->first();

        if (! $saleOrder) {
            throw new RuntimeException('Sale order not found or access denied.', 404);
        }

        return $saleOrder;
    }

    private function validateTransition(SaleOrder $saleOrder, SaleOrderStatus $toStatus): void
    {
        $fromStatus = $saleOrder->status_id;

        if ($fromStatus === SaleOrderStatus::CANCELLED) {
            throw new UnprocessableEntityException(
                'Cannot transition from Cancelled status. Cancelled orders are terminal.'
            );
        }

        if (! $this->isTransitionAllowed($fromStatus, $toStatus)) {
            throw new UnprocessableEntityException(
                sprintf('Invalid status transition from %s to %s.', $fromStatus->label(), $toStatus->label())
            );
        }

        if ($fromStatus === SaleOrderStatus::DRAFT && $toStatus === SaleOrderStatus::OPEN) {
            $this->validateDraftToOpen($saleOrder);
        }

        if ($toStatus === SaleOrderStatus::CANCELLED) {
            $this->validateCancellation($saleOrder);
        }

        if ($toStatus === SaleOrderStatus::COMPLETED) {
            $this->validateCompletion($saleOrder);
        }
    }

    private function isTransitionAllowed(SaleOrderStatus $from, SaleOrderStatus $to): bool
    {
        $allowedTargets = match ($from) {
            SaleOrderStatus::DRAFT => [SaleOrderStatus::OPEN, SaleOrderStatus::CANCELLED],
            SaleOrderStatus::OPEN => [SaleOrderStatus::CANCELLED],
            SaleOrderStatus::SHIPPED => [SaleOrderStatus::COMPLETED],
            default => []
        };

        return in_array($to, $allowedTargets, true);
    }

    private function validateDraftToOpen(SaleOrder $saleOrder): void
    {
        if (blank($saleOrder->customer_name)) {
            $message = 'Customer name is required to open a sale order.';

            throw UnprocessableEntityException::forField('customer_name', $message);
        }

        if (! $saleOrder->items()->exists()) {
            $message = 'Cannot open sale order without line items.';

            throw UnprocessableEntityException::forField('items', $message);
        }
    }

    private function validateCancellation(SaleOrder $saleOrder): void
    {
        $hasShippedShipments = $saleOrder->shipments()->where('status_id', SaleOrderShipmentStatus::SHIPPED)->exists();

        if ($hasShippedShipments) {
            throw new UnprocessableEntityException('Cannot cancel sale orders with shipped shipments.');
        }
    }

    private function validateCompletion(SaleOrder $saleOrder): void
    {
        $activeInvoices = $saleOrder->invoices()->where('status_id', SaleOrderInvoiceStatus::ACTIVE)->get();

        if ($activeInvoices->isEmpty()) {
            throw new UnprocessableEntityException('Cannot complete sale order: no active invoices found.');
        }

        $unpaidInvoices = $activeInvoices->filter(
            static fn ($invoice) => $invoice->payment_status !== SaleOrderInvoicePaymentStatus::PAID
        );

        if ($unpaidInvoices->isNotEmpty()) {
            throw new UnprocessableEntityException('Cannot complete sale order: all active invoices must be paid.');
        }
    }

    private function archiveActiveInvoices(SaleOrder $saleOrder): void
    {
        SaleOrderInvoice::where('sale_order_id', $saleOrder->id)
            ->where('status_id', SaleOrderInvoiceStatus::ACTIVE)
            ->lockForUpdate()
            ->get()
            ->each(static function (SaleOrderInvoice $invoice) {
                $invoice->update([
                    'status_id' => SaleOrderInvoiceStatus::ARCHIVED,
                    'payment_status' => $invoice->computeCurrentPaymentStatus(),
                ]);
            });
    }

    private function archiveActiveShipments(SaleOrder $saleOrder): void
    {
        $saleOrder
            ->shipments()
            ->whereStatusId(SaleOrderShipmentStatus::OPEN)
            ->lockForUpdate()
            ->update([
                'status_id' => SaleOrderShipmentStatus::ARCHIVED,
            ]);
    }

    private function logStatusTransition(
        SaleOrder $saleOrder,
        SaleOrderStatus $fromStatus,
        SaleOrderStatus $toStatus
    ): void {
        Log::info('Sale order status transitioned', [
            'sale_order_id' => $saleOrder->id,
            'sale_order_no' => $saleOrder->sale_order_no,
            'from_status' => $fromStatus->label(),
            'to_status' => $toStatus->label(),
            'user_id' => $saleOrder->user_id,
            'store_id' => $saleOrder->store_id,
        ]);
    }
}

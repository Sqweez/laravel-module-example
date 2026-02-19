<?php

namespace App\Service\SaleOrder\Invoice;

use App\DTO\Wholesale\Invoice\InvoiceCreateData;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Service\SaleOrder\Invoice\Concerns\RetryOnUniqueViolationTrait;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SaleOrderInvoiceCreateService
{
    use RetryOnUniqueViolationTrait;

    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly SaleOrderInvoiceItemAllocationService $itemAllocationService,
        private readonly SaleOrderInvoiceNumberingService $numberingService,
        private readonly SaleOrderInvoiceLockService $lockService,
        private readonly SaleOrderInvoiceEligibilityGuard $eligibilityGuard,
        private readonly SaleOrderInvoiceTotalsGuard $totalsGuard
    ) {
    }

    /**
     * @throws Throwable
     */
    public function create(SaleOrder $order, InvoiceCreateData $data, User $user): SaleOrderInvoice
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->attemptCreate($order->id, $data, $user);
            } catch (QueryException $e) {
                if (!$this->shouldRetryOnUniqueViolation($e, $attempt, self::MAX_ATTEMPTS)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to create invoice after maximum retry attempts', 500);
    }

    /**
     * @throws Throwable
     */
    private function attemptCreate(int $saleOrderId, InvoiceCreateData $data, User $user): SaleOrderInvoice
    {
        return DB::transaction(function () use ($saleOrderId, $data, $user) {
            $saleOrder = $this->lockService->lockSaleOrderWithItems($saleOrderId, $user);
            $this->eligibilityGuard->validate($saleOrder);

            $requestedQuantities = $this->itemAllocationService->normalizeRequestedQuantities($data->items);
            $this->itemAllocationService->assertItemsPresent($requestedQuantities);

            $saleOrderItems = $this->itemAllocationService->lockSaleOrderItems(
                $saleOrder,
                array_keys($requestedQuantities)
            );
            $invoicedQuantities = $this->itemAllocationService->getInvoicedQuantities(
                $saleOrder,
                array_keys($requestedQuantities)
            );
            $this->itemAllocationService->validateQuantities(
                $requestedQuantities,
                $saleOrderItems,
                $invoicedQuantities
            );

            $sequenceNo = $this->numberingService->getNextSequenceNumber($saleOrder);
            $invoiceNo = $this->numberingService->formatInvoiceNumber($saleOrder->sale_order_no, $sequenceNo);

            $invoice = $this->numberingService->createInvoiceHeader(
                $saleOrder,
                $user,
                $invoiceNo,
                $sequenceNo,
                $data->customer_name,
                $data->customer_address
            );
            $invoice->createWholesaleDocument();
            $this->itemAllocationService->createInvoiceItems($invoice, $saleOrderItems, $requestedQuantities);

            $invoice->load('items');
            $this->totalsGuard->validateInvoice($invoice);
            $invoice->calculateTotals();

            return $invoice->fresh(['items', 'saleOrder.payments']);
        });
    }
}

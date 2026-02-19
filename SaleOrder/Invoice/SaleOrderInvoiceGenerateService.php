<?php

namespace App\Service\SaleOrder\Invoice;

use App\Models\SaleOrder\SaleOrderInvoice;
use App\Service\SaleOrder\Invoice\Concerns\RetryOnUniqueViolationTrait;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SaleOrderInvoiceGenerateService
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
    public function generate(int $saleOrderId, User $user): SaleOrderInvoice
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->attemptGenerate($saleOrderId, $user);
            } catch (QueryException $e) {
                if (!$this->shouldRetryOnUniqueViolation($e, $attempt, self::MAX_ATTEMPTS)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to generate invoice after maximum retry attempts', 500);
    }

    /**
     * @throws Throwable
     */
    private function attemptGenerate(int $saleOrderId, User $user): SaleOrderInvoice
    {
        return DB::transaction(function () use ($saleOrderId, $user) {
            $saleOrder = $this->lockService->lockSaleOrderWithItems($saleOrderId, $user);
            $this->eligibilityGuard->validate($saleOrder);
            $this->totalsGuard->validateSaleOrder($saleOrder);

            $sequenceNo = $this->numberingService->getNextSequenceNumber($saleOrder);
            $invoiceNo = $this->numberingService->formatInvoiceNumber($saleOrder->sale_order_no, $sequenceNo);

            $invoice = $this->numberingService->createInvoiceHeader($saleOrder, $user, $invoiceNo, $sequenceNo);
            $invoice->createWholesaleDocument();
            $this->itemAllocationService->copyItemsFromSaleOrder($invoice, $saleOrder);
            $invoice->load('items');
            $this->totalsGuard->validateInvoice($invoice);
            $invoice->calculateTotals();

            return $invoice->fresh(['items', 'saleOrder.payments']);
        });
    }
}

<?php

namespace App\Service\SaleOrder\Bookkeeping;

use App\Models\Bookkeeping\BookkeepingJournalEntry;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderPayment;
use App\Models\SaleOrder\SaleOrderShipment;
use App\QuickbookAccess;
use App\Service\Bookkeeping\BookkeepingJournalService;
use App\Service\SaleOrder\Shipment\ShipmentTotalCalculator;
use Throwable;

class SaleOrderBookkeepingService
{
    private const string SOURCE_SALE_ORDER = 'sale_order';

    private const string SOURCE_SALE_ORDER_PAYMENT = 'sale_order_payment';

    private const string SOURCE_SALE_ORDER_SHIPMENT = 'sale_order_shipment';

    public function __construct(
        private readonly BookkeepingJournalService $bookkeepingJournalService,
        private readonly SaleOrderBookkeepingAccountsResolver $accountsResolver,
        private readonly SaleOrderBookkeepingAmountsCalculator $amountsCalculator,
        private readonly SaleOrderBookkeepingEntryFactory $entryFactory
    ) {
    }

    /**
     * @throws Throwable
     */
    public function recordOpen(SaleOrder $saleOrder): BookkeepingJournalEntry
    {
        $access = $this->accountsResolver->resolveQuickbookAccess($saleOrder);
        $amountCents = max(0, (int) $saleOrder->total_cents);

        return $this->bookkeepingJournalService->createEntryWithLines(
            entryData: $this->entryFactory->makeEntryData(
                saleOrder: $saleOrder,
                sourceType: self::SOURCE_SALE_ORDER,
                sourceId: $saleOrder->id,
                eventType: 'so_opened',
                triggerType: 'status_transition',
                idempotencyKey: sprintf('so:%d:opened:v1', $saleOrder->id),
                effectiveDate: $saleOrder->date_created?->toDateString()
            ),
            lines: [
                $this->entryFactory->debitLine(
                    $this->accountsResolver->resolveAccountsReceivableAccountId($access),
                    $amountCents,
                    'SO opened: Accounts Receivable'
                ),
                $this->entryFactory->creditLine(
                    $this->accountsResolver->resolveDeferredRevenueAccountId($access),
                    $amountCents,
                    'SO opened: Deferred Revenue - Wholesale'
                ),
            ]
        );
    }

    /**
     * @throws Throwable
     */
    public function recordPayment(SaleOrderPayment $payment): BookkeepingJournalEntry
    {
        $payment->loadMissing('saleOrder');

        $saleOrder = $payment->saleOrder;
        $access = $this->accountsResolver->resolveQuickbookAccess($saleOrder);
        $amountCents = abs($payment->amount_cents);

        if ($payment->amount_cents < 0) {
            return $this->recordRefundPayment($payment, $saleOrder, $access, $amountCents);
        }

        return $this->bookkeepingJournalService->createEntryWithLines(
            entryData: $this->entryFactory->makeEntryData(
                saleOrder: $saleOrder,
                sourceType: self::SOURCE_SALE_ORDER_PAYMENT,
                sourceId: $payment->id,
                eventType: 'sop_created',
                triggerType: 'payment_created',
                idempotencyKey: sprintf('sop:%d:created:v1', $payment->id),
                effectiveDate: $payment->created_at?->toDateString()
            ),
            lines: [
                $this->entryFactory->debitLine(
                    $this->accountsResolver->resolveMerchantAccountId($access, $payment->payment_method),
                    $amountCents,
                    sprintf('SO payment received via %s: Merchant Account', $payment->payment_method ?: 'general')
                ),
                $this->entryFactory->creditLine(
                    $this->accountsResolver->resolveAccountsReceivableAccountId($access),
                    $amountCents,
                    'SO payment received: Accounts Receivable'
                ),
            ]
        );
    }

    /**
     * @throws Throwable
     */
    public function recordShipmentShipped(SaleOrderShipment $shipment): BookkeepingJournalEntry
    {
        $shipment->loadMissing(['saleOrder', 'items.saleOrderItem']);

        $saleOrder = $shipment->saleOrder;
        $access = $this->accountsResolver->resolveQuickbookAccess($saleOrder);
        $shipmentTotals = ShipmentTotalCalculator::calculate($shipment);
        $refundedCents = $this->amountsCalculator->calculateRefundedCents($saleOrder, includeCompletedRefunds: false);
        $amountCents = max(0, $shipmentTotals->totalCents - $refundedCents);

        return $this->bookkeepingJournalService->createEntryWithLines(
            entryData: $this->entryFactory->makeEntryData(
                saleOrder: $saleOrder,
                sourceType: self::SOURCE_SALE_ORDER_SHIPMENT,
                sourceId: $shipment->id,
                eventType: 'sos_shipped',
                triggerType: 'shipment_transition',
                idempotencyKey: sprintf('sos:%d:shipped:v1', $shipment->id),
                effectiveDate: $shipment->document_date?->toDateString()
            ),
            lines: [
                $this->entryFactory->debitLine(
                    $this->accountsResolver->resolveDeferredRevenueAccountId($access),
                    $amountCents,
                    'SO shipment shipped: Deferred Revenue - Wholesale'
                ),
                $this->entryFactory->creditLine(
                    $this->accountsResolver->resolveWholesaleRevenueAccountId($access),
                    $amountCents,
                    'SO shipment shipped: Revenue - Wholesale'
                ),
            ]
        );
    }

    /**
     * @throws Throwable
     */
    public function recordCompleted(SaleOrder $saleOrder): BookkeepingJournalEntry
    {
        $saleOrder->loadMissing('payments');

        $access = $this->accountsResolver->resolveQuickbookAccess($saleOrder);
        $refundedCents = $this->amountsCalculator->calculateRefundedCents($saleOrder, includeCompletedRefunds: false);
        $recognizedTotalCents = max(0, $saleOrder->total_cents - $refundedCents);
        $hasSplitRevenue = $refundedCents === 0;

        $lines = [
            $this->entryFactory->debitLine(
                $this->accountsResolver->resolveDeferredRevenueAccountId($access),
                $recognizedTotalCents,
                'SO completed: Deferred Revenue - Wholesale'
            ),
        ];

        if ($hasSplitRevenue && $saleOrder->discount_cents > 0) {
            $grossRevenue = max(0, $saleOrder->subtotal_cents + $saleOrder->shipping_cents);
            $lines[] = $this->entryFactory->debitLine(
                $this->accountsResolver->resolveDiscountAccountId($access),
                $saleOrder->discount_cents,
                'SO completed: Discount'
            );
            $lines[] = $this->entryFactory->creditLine(
                $this->accountsResolver->resolveWholesaleRevenueAccountId($access),
                $grossRevenue,
                'SO completed: Revenue - Wholesale (gross)'
            );
        } elseif ($hasSplitRevenue && $saleOrder->shipping_cents > 0) {
            $lines[] = $this->entryFactory->creditLine(
                $this->accountsResolver->resolveWholesaleRevenueAccountId($access),
                max(0, $saleOrder->subtotal_cents - $saleOrder->discount_cents),
                'SO completed: Revenue - Wholesale (products)'
            );
            $lines[] = $this->entryFactory->creditLine(
                $this->accountsResolver->resolveShippingRevenueAccountId($access),
                $saleOrder->shipping_cents,
                'SO completed: Shipping Revenue'
            );
        } else {
            $lines[] = $this->entryFactory->creditLine(
                $this->accountsResolver->resolveWholesaleRevenueAccountId($access),
                $recognizedTotalCents,
                'SO completed: Revenue - Wholesale'
            );
        }

        return $this->bookkeepingJournalService->createEntryWithLines(
            entryData: $this->entryFactory->makeEntryData(
                saleOrder: $saleOrder,
                sourceType: self::SOURCE_SALE_ORDER,
                sourceId: $saleOrder->id,
                eventType: 'so_completed',
                triggerType: 'status_transition',
                idempotencyKey: sprintf('so:%d:completed:v1', $saleOrder->id),
                effectiveDate: $saleOrder->date_completed?->toDateString()
            ),
            lines: $lines
        );
    }

    /**
     * @return array<int, BookkeepingJournalEntry>
     */
    public function recordPaymentDeletionReversal(SaleOrderPayment $payment): array
    {
        $payment->loadMissing('saleOrder');

        $entries = BookkeepingJournalEntry::query()
            ->where('source_type', self::SOURCE_SALE_ORDER_PAYMENT)
            ->where('source_id', $payment->id)
            ->whereNull('reversal_of_entry_id')
            ->get();

        return $entries->map(function (BookkeepingJournalEntry $entry) use ($payment) {
            return $this->bookkeepingJournalService->createReversalEntry(
                originalEntry: $entry,
                idempotencyKey: sprintf('sop:%d:deleted:reverse:%d:v1', $payment->id, $entry->id),
            );
        })->values()->all();
    }

    /**
     * @throws Throwable
     */
    private function recordRefundPayment(
        SaleOrderPayment $payment,
        SaleOrder $saleOrder,
        QuickbookAccess $access,
        int $amountCents
    ): BookkeepingJournalEntry {
        $isCompleted = $saleOrder->status_id === SaleOrderStatus::COMPLETED;

        return $this->bookkeepingJournalService->createEntryWithLines(
            entryData: $this->entryFactory->makeEntryData(
                saleOrder: $saleOrder,
                sourceType: self::SOURCE_SALE_ORDER_PAYMENT,
                sourceId: $payment->id,
                eventType: 'sop_refund_created',
                triggerType: 'payment_created',
                idempotencyKey: sprintf('sop:%d:refund:v1', $payment->id),
                effectiveDate: $payment->created_at?->toDateString()
            ),
            lines: [
                $this->entryFactory->creditLine(
                    $this->accountsResolver->resolveMerchantAccountId($access, $payment->payment_method),
                    $amountCents,
                    sprintf('SO refund via %s: Merchant Account', $payment->payment_method ?: 'general')
                ),
                $this->entryFactory->debitLine(
                    $isCompleted
                        ? $this->accountsResolver->resolveRefundAccountId($access)
                        : $this->accountsResolver->resolveDeferredRevenueAccountId($access),
                    $amountCents,
                    $isCompleted
                        ? 'SO refund after completion: Refund Account'
                        : 'SO refund before completion: Deferred Revenue - Wholesale'
                ),
            ]
        );
    }
}

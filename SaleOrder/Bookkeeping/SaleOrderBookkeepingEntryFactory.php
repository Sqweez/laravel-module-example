<?php

namespace App\Service\SaleOrder\Bookkeeping;

use App\DTO\Bookkeeping\BookkeepingJournalEntryData;
use App\DTO\Bookkeeping\BookkeepingJournalEntryLineData;
use App\Models\SaleOrder\SaleOrder;

class SaleOrderBookkeepingEntryFactory
{
    private const string AGGREGATE_SALE_ORDER = 'sale_order';

    private const string POSTING_DEBIT = 'debit';

    private const string POSTING_CREDIT = 'credit';

    public function makeEntryData(
        SaleOrder $saleOrder,
        string $sourceType,
        ?int $sourceId,
        string $eventType,
        string $triggerType,
        string $idempotencyKey,
        ?string $effectiveDate
    ): BookkeepingJournalEntryData {
        return new BookkeepingJournalEntryData(
            storeId: $saleOrder->store_id,
            sourceType: $sourceType,
            sourceId: $sourceId,
            aggregateType: self::AGGREGATE_SALE_ORDER,
            aggregateId: $saleOrder->id,
            eventType: $eventType,
            triggerType: $triggerType,
            effectiveAt: $this->resolveEffectiveDate($effectiveDate),
            idempotencyKey: $idempotencyKey,
            reversalOfEntryId: null,
            meta: null,
        );
    }

    public function debitLine(
        int $accountCode,
        int $amountCents,
        ?string $description = null
    ): BookkeepingJournalEntryLineData {
        return $this->line($accountCode, self::POSTING_DEBIT, $amountCents, $description);
    }

    public function creditLine(
        int $accountCode,
        int $amountCents,
        ?string $description = null
    ): BookkeepingJournalEntryLineData {
        return $this->line($accountCode, self::POSTING_CREDIT, $amountCents, $description);
    }

    public function resolveEffectiveDate(?string $date): string
    {
        return $date ?: now()->toDateString();
    }

    private function line(
        int $accountCode,
        string $postingType,
        int $amountCents,
        ?string $description = null
    ): BookkeepingJournalEntryLineData {
        return new BookkeepingJournalEntryLineData(
            accountCode: $accountCode,
            postingType: $postingType,
            amount: $amountCents,
            description: $description
        );
    }
}

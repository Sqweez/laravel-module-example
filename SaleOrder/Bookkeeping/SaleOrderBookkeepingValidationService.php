<?php

namespace App\Service\SaleOrder\Bookkeeping;

use App\Models\Bookkeeping\BookkeepingJournalEntryLine;
use App\Models\SaleOrder\SaleOrder;

class SaleOrderBookkeepingValidationService
{
    private const string AGGREGATE_SALE_ORDER = 'sale_order';

    private const string POSTING_DEBIT = 'debit';

    private const string POSTING_CREDIT = 'credit';

    public function __construct(
        private readonly SaleOrderBookkeepingAccountsResolver $accountsResolver,
        private readonly SaleOrderBookkeepingAmountsCalculator $amountsCalculator
    ) {
    }

    /**
     * @return array{is_balanced:bool, issues:array<int,string>, totals:array<string,mixed>}
     */
    public function validateAndMarkException(SaleOrder $saleOrder): array
    {
        $totals = $this->collectTotals($saleOrder);
        $issues = [];

        if (($totals['accounts_receivable']['debit'] ?? 0) !== ($totals['accounts_receivable']['credit'] ?? 0)) {
            $issues[] = 'Accounts Receivable debits and credits are not balanced.';
        }

        if (($totals['deferred_revenue_wholesale']['debit'] ?? 0) !== ($totals['deferred_revenue_wholesale']['credit'] ?? 0)) {
            $issues[] = 'Deferred Revenue debits and credits are not balanced.';
        }

        if (($totals['wholesale_revenue_credit'] ?? 0) !== ($totals['expected_wholesale_revenue_credit'] ?? 0)) {
            $issues[] = 'Wholesale Revenue credits do not match expected recognized amount.';
        }

        if (($totals['cash_accounts_debit'] ?? 0) !== ($totals['expected_cash_accounts_debit'] ?? 0)) {
            $issues[] = 'Cash account debits do not match expected received payments.';
        }

        $isBalanced = $issues === [];

        $saleOrder->update([
            'bookkeeping_exception' => ! $isBalanced,
            'bookkeeping_exception_payload' => $isBalanced ? null : [
                'issues' => $issues,
                'totals' => $totals,
            ],
            'bookkeeping_last_checked_at' => now(),
        ]);

        return [
            'is_balanced' => $isBalanced,
            'issues' => $issues,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectTotals(SaleOrder $saleOrder): array
    {
        $access = $this->accountsResolver->resolveQuickbookAccess($saleOrder);

        $accountsReceivableAccountId = $this->accountsResolver->resolveAccountsReceivableAccountId($access);
        $deferredRevenueAccountId = $this->accountsResolver->resolveDeferredRevenueAccountId($access);
        $wholesaleRevenueAccountId = $this->accountsResolver->resolveWholesaleRevenueAccountId($access);
        $lineSums = BookkeepingJournalEntryLine::query()
            ->selectRaw('account_code, posting_type, SUM(amount) as total_cents')
            ->whereHas('entry', function ($query) use ($saleOrder) {
                $query
                    ->where('aggregate_type', self::AGGREGATE_SALE_ORDER)
                    ->where('aggregate_id', $saleOrder->id);
            })
            ->groupBy('account_code', 'posting_type')
            ->get();

        $resolved = [];
        foreach ($lineSums as $row) {
            $resolved[(int) $row->account_code][$row->posting_type] = (int) $row->total_cents;
        }

        $refundedBeforeCompletion = $this->amountsCalculator->refundedBeforeCompletion($saleOrder);
        $expectedRecognized = max(0, (int) $saleOrder->total_cents - $refundedBeforeCompletion);
        $expectedCashDebits = (int) $saleOrder->payments()->where('amount_cents', '>', 0)->sum('amount_cents');
        $actualCashDebits = (int) BookkeepingJournalEntryLine::query()
            ->where('posting_type', self::POSTING_DEBIT)
            ->whereHas('entry', function ($query) use ($saleOrder) {
                $query
                    ->where('aggregate_type', self::AGGREGATE_SALE_ORDER)
                    ->where('aggregate_id', $saleOrder->id)
                    ->where('event_type', 'sop_created');
            })
            ->sum('amount');

        return [
            'accounts_receivable' => [
                'debit' => $resolved[$accountsReceivableAccountId][self::POSTING_DEBIT] ?? 0,
                'credit' => $resolved[$accountsReceivableAccountId][self::POSTING_CREDIT] ?? 0,
            ],
            'deferred_revenue_wholesale' => [
                'debit' => $resolved[$deferredRevenueAccountId][self::POSTING_DEBIT] ?? 0,
                'credit' => $resolved[$deferredRevenueAccountId][self::POSTING_CREDIT] ?? 0,
            ],
            'wholesale_revenue_credit' => $resolved[$wholesaleRevenueAccountId][self::POSTING_CREDIT] ?? 0,
            'expected_wholesale_revenue_credit' => $expectedRecognized,
            'cash_accounts_debit' => $actualCashDebits,
            'expected_cash_accounts_debit' => $expectedCashDebits,
        ];
    }
}

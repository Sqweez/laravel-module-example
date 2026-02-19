<?php

namespace App\Service\SaleOrder\Bookkeeping;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\SaleOrder;
use App\QuickbookAccess;
use App\Resolvers\Quickbooks\QuickbooksSetupValidator;

class SaleOrderBookkeepingAccountsResolver
{
    private const string WHOLESALE_MAPPING_NOT_CONFIGURED_MESSAGE = 'Wholesale module account mapping is not configured.';

    /**
     * @var array<int, QuickbookAccess>
     */
    private array $quickbookAccessCache = [];

    public function resolveQuickbookAccess(SaleOrder $saleOrder): QuickbookAccess
    {
        $userId = $saleOrder->user_id;

        if (isset($this->quickbookAccessCache[$userId])) {
            return $this->quickbookAccessCache[$userId];
        }

        $access = QuickbookAccess::query()->where('user_id', $userId)->first();

        if (! $access) {
            throw UnprocessableEntityException::forField(
                'quickbooks_access',
                'QuickBooks access is not configured for this sale order user.'
            );
        }

        $this->assertWholesaleConfigured($access);

        $this->quickbookAccessCache[$userId] = $access;

        return $access;
    }

    public function resolveAccountsReceivableAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_receivable_account_id,
            'wholesale_receivable_account_id'
        );
    }

    public function resolveDeferredRevenueAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_deferred_revenue_account_id,
            'wholesale_deferred_revenue_account_id'
        );
    }

    public function resolveWholesaleRevenueAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_revenue_account_id,
            'wholesale_revenue_account_id'
        );
    }

    public function resolveShippingRevenueAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_shipping_account_id,
            'wholesale_shipping_account_id'
        );
    }

    public function resolveDiscountAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_discount_account_id,
            'wholesale_discount_account_id'
        );
    }

    public function resolveRefundAccountId(QuickbookAccess $access): int
    {
        return $this->requireAccountId(
            $access->wholesale_refund_account_id,
            'wholesale_refund_account_id'
        );
    }

    public function resolveMerchantAccountId(QuickbookAccess $access, string $paymentMethod): int
    {
        $normalizedMethod = mb_strtolower($paymentMethod);
        $normalizedMethod = $normalizedMethod === '' ? 'general' : $normalizedMethod;
        $merchantAccounts = collect($access->merchant_accounts_by_payment_type ?? []);
        $mappedAccount = $merchantAccounts->firstWhere('key', $normalizedMethod);

        if (! is_array($mappedAccount) || (int) ($mappedAccount['value'] ?? 0) <= 0) {
            return $this->requireAccountId($access->merchant_account_id, 'merchant_account_id');
        }

        return $this->requireAccountId(
            (int) ($mappedAccount['value'] ?? 0),
            "merchant_accounts_by_payment_type.{$normalizedMethod}"
        );
    }

    private function requireAccountId(?int $accountId, string $field): int
    {
        if ($accountId && $accountId > 0) {
            return $accountId;
        }

        throw UnprocessableEntityException::forField(
            'quickbooks_account',
            "QuickBook access account is not configured: {$field}"
        );
    }

    private function assertWholesaleConfigured(QuickbookAccess $access): void
    {
        if (QuickbooksSetupValidator::isWholesaleConfigured($access)) {
            return;
        }

        throw new UnprocessableEntityException(self::WHOLESALE_MAPPING_NOT_CONFIGURED_MESSAGE);
    }
}

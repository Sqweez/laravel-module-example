<?php

namespace App\Service\SaleOrder\Invoice;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Support\PaymentTermsMethods;
use App\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SaleOrderInvoiceUpdateService
{
    /** @var list<string> */
    private const array UPDATABLE_FIELDS = [
        'customer_name',
        'customer_address',
        'payment_terms_type',
        'payment_terms_days',
        'notes',
        'shipping_cents',
        'discount_cents',
    ];

    /** @var list<string> */
    private const array MONETARY_FIELDS = ['shipping_cents', 'discount_cents'];

    public function __construct(
        private readonly SaleOrderInvoiceLockService $lockService,
        private readonly SaleOrderInvoiceTotalsGuard $totalsGuard,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function update(int $id, array $payload, User $user): SaleOrderInvoice
    {
        return DB::transaction(function () use ($id, $payload, $user): SaleOrderInvoice {
            $invoice = $this->lockService->lockInvoiceForUpdate($id, $user);
            $this->ensureActive($invoice);

            $updates = $this->prepareUpdates($payload);
            if ($updates === []) {
                return $invoice->fresh(['items', 'saleOrder.payments']);
            }

            $monetaryChanged = $this->hasMonetaryChanges($updates);

            $invoice->fill($updates)->save();

            if (! $monetaryChanged) {
                return $invoice->fresh(['items', 'saleOrder.payments']);
            }

            $invoice->loadMissing('items');

            $newTotal = $this->computeInvoiceTotal(
                invoice: $invoice,
                shippingCents: $this->resolveInt($updates, 'shipping_cents', $invoice->shipping_cents),
                discountCents: $this->resolveInt($updates, 'discount_cents', $invoice->discount_cents),
            );

            $this->totalsGuard->validateTotal($newTotal);

            $invoice->calculateTotals();

            return $invoice->fresh(['items', 'saleOrder.payments']);
        });
    }

    private function ensureActive(SaleOrderInvoice $invoice): void
    {
        if ($invoice->status_id === SaleOrderInvoiceStatus::ACTIVE) {
            return;
        }

        throw new UnprocessableEntityException('Invoice must be active to edit');
    }

    /** @return array<string,mixed> */
    private function prepareUpdates(array $payload): array
    {
        $updates = $this->onlyUpdatable($payload);
        if ($updates === []) {
            return [];
        }

        $updates = $this->normalizePaymentTerms($updates);

        return $this->stripNullMonetary($updates);
    }

    /** @return array<string,mixed> */
    private function onlyUpdatable(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::UPDATABLE_FIELDS));
    }

    /** @param array<string,mixed> $updates
     * @return array<string,mixed>
     */
    private function normalizePaymentTerms(array $updates): array
    {
        return PaymentTermsMethods::normalizeUpdateArray($updates);
    }

    /** @param array<string,mixed> $updates
     * @return array<string,mixed>
     */
    private function stripNullMonetary(array $updates): array
    {
        foreach (self::MONETARY_FIELDS as $field) {
            if (! array_key_exists($field, $updates)) {
                continue;
            }

            if ($updates[$field] !== null) {
                continue;
            }

            unset($updates[$field]);
        }

        return $updates;
    }

    /** @param array<string,mixed> $updates */
    private function hasMonetaryChanges(array $updates): bool
    {
        return array_intersect_key($updates, array_flip(self::MONETARY_FIELDS)) !== [];
    }

    /** @param array<string,mixed> $updates */
    private function resolveInt(array $updates, string $key, int $fallback): int
    {
        if (! array_key_exists($key, $updates)) {
            return $fallback;
        }

        $value = $updates[$key];

        return is_int($value) ? $value : (int) $value;
    }

    private function computeInvoiceTotal(SaleOrderInvoice $invoice, int $shippingCents, int $discountCents): int
    {
        $subtotalCents = (int) $invoice->items->sum('total_cents');

        return $subtotalCents + $shippingCents - $discountCents;
    }
}

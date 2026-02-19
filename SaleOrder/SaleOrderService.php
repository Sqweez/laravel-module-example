<?php

namespace App\Service\SaleOrder;

use App\DTO\Wholesale\SaleOrder\SaleOrderCreateData;
use App\DTO\Wholesale\SaleOrder\SaleOrderPreviewData;
use App\DTO\Wholesale\SaleOrder\SaleOrderUpdateData;
use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Service\Numbering\SaleOrderNumberingService;
use App\Support\PaymentTermsMethods;
use App\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SaleOrderService
{
    private const array EDITABLE_STATUSES = [
        SaleOrderStatus::DRAFT,
        SaleOrderStatus::OPEN,
    ];

    private const int MAX_CREATE_ATTEMPTS = 3;

    public function __construct(
        private readonly SaleOrderNumberingService $numberingService,
        private readonly SaleOrderItemSyncService $itemSyncService,
        private readonly SaleOrderTotalsCalculator $totalsCalculator,
        private readonly SaleOrderStatusTransitionService $statusTransitionService
    ) {
    }

    /**
     * @throws Throwable
     */
    public function create(SaleOrderCreateData $payload, User $user): SaleOrder
    {
        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                return $this->attemptCreate($payload, $user);
            } catch (QueryException $e) {
                if ($this->shouldRetryCreate($e, $attempt, $user)) {
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException('Failed to create sale order after maximum retry attempts', 500);
    }

    /**
     * Preview sale order totals without persisting.
     */
    public function preview(SaleOrderPreviewData $payload, User $user): SaleOrder
    {
        $saleOrder = new SaleOrder([
            'user_id' => $user->id,
            'store_id' => $user->store->id,
            'sale_order_no' => null,
            'customer_name' => $payload->customerName,
            'customer_address' => $payload->customerAddress,
            'payment_terms_type' => $payload->paymentTermsType,
            'payment_terms_days' => $payload->paymentTermsDays,
            'date_created' => now()->timezone($user->retrieveTimezone())->toDateString(),
            'status_id' => SaleOrderStatus::DRAFT,
            'shipping_cents' => $payload->getShippingCents(),
            'discount_cents' => 0,
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'notes' => $payload->notes,
            'external_reference' => $payload->externalReference,
        ]);

        $items = $this->totalsCalculator->buildPreviewItems($payload->items ?? []);
        $saleOrder->setRelation('items', $items);
        $saleOrder->setRelation('shipments', new Collection());
        $saleOrder->setRelation('invoices', new Collection());
        $saleOrder->setAttribute('shipments_count', 0);
        $saleOrder->setAttribute('has_active_invoice', false);

        $saleOrder->subtotal_cents = $items->sum('total_cents');
        $this->applyPreviewDiscount($payload, $saleOrder);
        $saleOrder->total_cents = $this->totalsCalculator->calculateTotal(
            $saleOrder->subtotal_cents,
            $saleOrder->shipping_cents,
            $saleOrder->discount_cents
        );

        return $saleOrder;
    }

    /**
     * @throws Throwable
     */
    public function updateOrder(int $id, SaleOrderUpdateData $payload, User $user): SaleOrder
    {
        return DB::transaction(function () use ($id, $payload, $user) {
            $saleOrder = $this->lockAndLoadSaleOrder($id, $user);
            $monetaryFieldsChanged = $this->updateHeaderFromDto($saleOrder, $payload);

            if ($payload->hasItems()) {
                $itemsArray = [];
                foreach ($payload->items as $item) {
                    $itemsArray[] = $item->toArray();
                }
                $this->itemSyncService->syncItems($saleOrder, $itemsArray);
                $monetaryFieldsChanged = true;
            }

            if ($payload->hasDiscountPercent()) {
                $monetaryFieldsChanged = true;
            }

            if ($monetaryFieldsChanged) {
                $saleOrder->refresh();
                $this->applyDiscountFromUpdatePayload($payload, $saleOrder);
                $this->totalsCalculator->validateTotal($saleOrder);
                $saleOrder->calculateTotals();
            }

            return $saleOrder->fresh(['items']);
        });
    }

    /**
     * @throws Throwable
     */
    public function transitionStatus(int $id, SaleOrderStatus $toStatus, User $user): SaleOrder
    {
        return $this->statusTransitionService->transition($id, $toStatus, $user);
    }

    /**
     * @throws Throwable
     */
    private function attemptCreate(SaleOrderCreateData $payload, User $user): SaleOrder
    {
        return DB::transaction(function () use ($payload, $user) {
            $saleOrder = $this->createSaleOrderRecord($payload, $user);

            if ($payload->hasItems()) {
                $itemsArray = [];
                foreach ($payload->items as $item) {
                    $itemsArray[] = $item->toArray();
                }
                $this->itemSyncService->syncItems($saleOrder, $itemsArray);
            }

            $saleOrder->refresh();
            $this->applyDiscountFromCreatePayload($payload, $saleOrder);
            $this->totalsCalculator->validateTotal($saleOrder);
            $saleOrder->calculateTotals();
            $saleOrder->createWholesaleDocument();
            $this->createContact($payload, $user);

            return $saleOrder->fresh(['items']);
        });
    }

    private function createContact(SaleOrderCreateData $payload, User $user): void
    {
        if (!$payload->shouldCreateContact) {
            return ;
        }

        $user->store->storeContacts()->create([
            'name' => $payload->customerName,
            'addresses' => [$payload->customerAddress],
        ]);
    }

    private function createSaleOrderRecord(SaleOrderCreateData $payload, User $user): SaleOrder
    {
        return SaleOrder::create([
            'user_id' => $user->id,
            'store_id' => $user->store->id,
            'sale_order_no' => $this->numberingService->generateNextNumber($user->store),
            'customer_name' => $payload->customerName,
            'customer_address' => $payload->customerAddress,
            'payment_terms_type' => $payload->paymentTermsType,
            'payment_terms_days' => $payload->paymentTermsDays,
            'date_created' => now()->timezone($user->retrieveTimezone())->toDateString(),
            'status_id' => SaleOrderStatus::DRAFT,
            'shipping_cents' => $payload->getShippingCents(),
            'discount_cents' => $payload->getDiscountCents(),
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'notes' => $payload->notes,
            'external_reference' => $payload->externalReference,
        ]);
    }

    private function shouldRetryCreate(QueryException $e, int $attempt, User $user): bool
    {
        if ($attempt >= self::MAX_CREATE_ATTEMPTS) {
            return false;
        }

        $isUniqueViolation = in_array($e->getCode(), ['23000', '23505'], true);

        if ($isUniqueViolation) {
            Log::warning('Sale order number collision, retrying', [
                'attempt' => $attempt,
                'user_id' => $user->id,
                'store_id' => $user->store->id,
            ]);
        }

        return $isUniqueViolation;
    }

    private function updateHeaderFromDto(SaleOrder $saleOrder, SaleOrderUpdateData $payload): bool
    {
        $updates = $payload->toHeaderArray();

        if ($updates === []) {
            return false;
        }

        $updates = $this->normalizePaymentTerms($updates);

        $monetaryFieldsChanged =
            array_key_exists('shipping_cents', $updates) || array_key_exists('discount_cents', $updates);

        $saleOrder->fill($updates)->save();

        return $monetaryFieldsChanged;
    }

    private function lockAndLoadSaleOrder(int $id, User $user): SaleOrder
    {
        $saleOrder = SaleOrder::where('id', $id)
            ->where('store_id', $user->store->id)
            ->whereIn('status_id', self::EDITABLE_STATUSES)
            ->lockForUpdate()
            ->first();

        if (! $saleOrder) {
            Log::warning('Sale order edit denied', [
                'sale_order_id' => $id,
                'user_id' => $user->id,
                'store_id' => $user->store->id,
            ]);
            throw new UnprocessableEntityException('Sale order not found or cannot be edited.');
        }

        return $saleOrder;
    }

    /** @param array<string, mixed> $updates */
    private function normalizePaymentTerms(array $updates): array
    {
        return PaymentTermsMethods::normalizeUpdateArray($updates);
    }

    private function applyPreviewDiscount(SaleOrderPreviewData $payload, SaleOrder $saleOrder): void
    {
        if ($payload->hasDiscountCents()) {
            $saleOrder->discount_cents = $payload->getDiscountCents();

            return;
        }

        if ($payload->hasDiscountPercent()) {
            $base = $saleOrder->subtotal_cents + $saleOrder->shipping_cents;
            $saleOrder->discount_cents = $this->totalsCalculator->calculateDiscountCents(
                $base,
                $payload->getDiscountPercent()
            );
        }
    }

    private function applyDiscountFromCreatePayload(SaleOrderCreateData $payload, SaleOrder $saleOrder): void
    {
        if ($payload->hasDiscountCents()) {
            $saleOrder->discount_cents = $payload->getDiscountCents();

            return;
        }

        if ($payload->hasDiscountPercent()) {
            $subtotalCents = $saleOrder->items()->sum('total_cents');
            $base = $subtotalCents + $payload->getShippingCents();
            $saleOrder->discount_cents = $this->totalsCalculator->calculateDiscountCents(
                $base,
                $payload->getDiscountPercent()
            );
        }
    }

    private function applyDiscountFromUpdatePayload(SaleOrderUpdateData $payload, SaleOrder $saleOrder): void
    {
        if ($payload->hasDiscountCents()) {
            $saleOrder->discount_cents = $payload->getDiscountCents();

            return;
        }

        if ($payload->hasDiscountPercent()) {
            $subtotalCents = $saleOrder->items()->sum('total_cents');
            $base = $subtotalCents + $saleOrder->shipping_cents;
            $saleOrder->discount_cents = $this->totalsCalculator->calculateDiscountCents(
                $base,
                $payload->getDiscountPercent()
            );
        }
    }
}

<?php

namespace App\Service\SaleOrder\Shipment;

use App\DTO\Wholesale\SaleOrder\SaleOrderShipmentCreateData;
use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\Enums\SaleOrderShipmentStatus;
use App\Models\SaleOrder\Enums\SaleOrderStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderItem;
use App\Models\SaleOrder\SaleOrderShipment;
use App\Models\SaleOrder\SaleOrderShipmentItem;
use App\Service\Numbering\SaleOrderShipmentNumberingService;
use App\Service\SaleOrder\Bookkeeping\SaleOrderBookkeepingService;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SaleOrderShipmentService
{
    private const array SHIPPABLE_STATUSES = [
        SaleOrderStatus::OPEN,
        SaleOrderStatus::PARTIALLY_SHIPPED,
    ];

    private const int MAX_CREATE_ATTEMPTS = 3;

    public function __construct(
        private readonly SaleOrderShipmentNumberingService $numberingService,
        private readonly SaleOrderBookkeepingService $saleOrderBookkeepingService
    ) {
    }

    /**
     * @throws Throwable
     */
    public function createShipment(SaleOrder $saleOrder, SaleOrderShipmentCreateData $data, User $user): SaleOrderShipment
    {
        $saleOrderId = $saleOrder->id;

        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($saleOrderId, $data, $user) {
                    $saleOrder = $this->lockSaleOrder($saleOrderId, $user);

                    $this->ensureOrderCanCreateShipment($saleOrder);
                    $this->ensureOrderHasActiveInvoice($saleOrder, 'Cannot create shipment without an active invoice.');
                    $this->validateItems($saleOrder, $data->items);

                    $shipment = $this->persistShipment($saleOrder, $data, $user);
                    $this->syncShipmentItems($shipment, $data->items);

                    return $shipment->fresh(['items.saleOrderItem', 'saleOrder']);
                });
            } catch (QueryException $e) {
                if (! $this->shouldRetryCreate($e, $attempt, $saleOrderId)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to create shipment after maximum retry attempts.', 500);
    }

    /**
     * @deprecated
     *
     * @throws Throwable
     */
    public function updateShipment(int $shipmentId, array $payload, User $user): SaleOrderShipment
    {
        $itemIds = $payload['items'];

        return DB::transaction(function () use ($shipmentId, $payload, $itemIds, $user) {
            [$shipment, $saleOrder] = $this->lockShipmentAndOrder($shipmentId, $user);

            $this->ensureShipmentIsEditable($shipment);
            $this->ensureOrderNotCancelled($saleOrder, 'Cannot update shipment when parent sale order is cancelled.');

            if ($itemIds !== null) {
                $this->validateItems($saleOrder, $itemIds, $shipment->id);
                $this->syncShipmentItems($shipment, $itemIds);
            }

            $this->updateShipmentFields($shipment, $payload);

            return $shipment->fresh(['items.saleOrderItem', 'saleOrder']);
        });
    }

    /**
     * @throws Throwable
     */
    public function transitionStatus(
        SaleOrderShipment $shipment,
        SaleOrderShipmentStatus $toStatus,
        User $user
    ): SaleOrderShipment {
        return DB::transaction(function () use ($shipment, $toStatus, $user) {
            [$shipment, $saleOrder] = $this->lockShipmentAndOrder($shipment->id, $user);

            $this->ensureOrderNotCancelled(
                $saleOrder,
                'Cannot change shipment status when parent sale order is cancelled.'
            );
            $this->validateShipmentTransition($shipment, $toStatus, $saleOrder);

            $fromStatus = $shipment->status_id;
            $shipment->update(['status_id' => $toStatus]);

            if ($toStatus === SaleOrderShipmentStatus::SHIPPED) {
                $this->saleOrderBookkeepingService->recordShipmentShipped($shipment);
            }

            if ($this->requiresOrderStatusUpdate($fromStatus, $toStatus)) {
                $this->updateParentOrderStatus($saleOrder);
            }

            return $shipment->fresh(['items.saleOrderItem', 'saleOrder']);
        });
    }

    private function lockSaleOrder(int $saleOrderId, User $user): SaleOrder
    {
        $saleOrder = SaleOrder::where('id', $saleOrderId)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->first();

        if (! $saleOrder) {
            throw new RuntimeException('Sale order not found or access denied.', 404);
        }

        return $saleOrder;
    }

    private function lockShipmentAndOrder(int $shipmentId, User $user): array
    {
        $shipment = SaleOrderShipment::where('id', $shipmentId)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->firstOrFail();

        $saleOrder = SaleOrder::where('id', $shipment->sale_order_id)
            ->where('store_id', $user->store->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$shipment, $saleOrder];
    }

    private function ensureOrderCanCreateShipment(SaleOrder $saleOrder): void
    {
        if (! in_array($saleOrder->status_id, self::SHIPPABLE_STATUSES, true)) {
            throw new UnprocessableEntityException('Shipments can only be created for open orders.');
        }
    }

    private function ensureOrderHasActiveInvoice(SaleOrder $saleOrder, string $message): void
    {
        if (! $this->hasActiveInvoice($saleOrder)) {
            throw new UnprocessableEntityException($message);
        }
    }

    private function hasActiveInvoice(SaleOrder $saleOrder): bool
    {
        return $saleOrder->invoices()->where('status_id', SaleOrderInvoiceStatus::ACTIVE)->exists();
    }

    private function ensureShipmentIsEditable(SaleOrderShipment $shipment): void
    {
        if (! $shipment->status_id->isEditable()) {
            throw new UnprocessableEntityException('Only open shipments may be edited.');
        }
    }

    private function ensureOrderNotCancelled(SaleOrder $saleOrder, string $message): void
    {
        if ($saleOrder->status_id === SaleOrderStatus::CANCELLED) {
            throw new UnprocessableEntityException($message);
        }
    }

    private function validateItems(SaleOrder $saleOrder, array $itemIds, ?int $excludeShipmentId = null): void
    {
        $this->assertItemsPresent($itemIds);
        $this->validateItemsBelongToOrder($saleOrder, $itemIds);
        $this->validateItemsNotShipped($saleOrder, $itemIds, $excludeShipmentId);
    }

    private function assertItemsPresent(array $itemIds): void
    {
        if (empty($itemIds)) {
            $message = 'Shipment must include at least one item.';

            throw UnprocessableEntityException::forField('items', $message);
        }
    }

    private function validateItemsBelongToOrder(SaleOrder $saleOrder, array $itemIds): void
    {
        // Lock the items to prevent concurrent shipment creation with the same items
        $count = SaleOrderItem::where('sale_order_id', $saleOrder->id)
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->count();

        if ($count !== count($itemIds)) {
            $message = 'One or more selected items do not belong to this sale order.';

            throw UnprocessableEntityException::forField('items', $message);
        }
    }

    private function validateItemsNotShipped(SaleOrder $saleOrder, array $itemIds, ?int $excludeShipmentId = null): void
    {
        $alreadyShipped = SaleOrderShipmentItem::query()
            ->whereIn('sale_order_item_id', $itemIds)
            ->whereHas('shipment', function ($query) use ($saleOrder, $excludeShipmentId) {
                $query->where('sale_order_id', $saleOrder->id)->where('status_id', SaleOrderShipmentStatus::SHIPPED);

                if ($excludeShipmentId !== null) {
                    $query->where('id', '!=', $excludeShipmentId);
                }
            })
            ->pluck('sale_order_item_id')
            ->unique()
            ->all();

        if (empty($alreadyShipped)) {
            return;
        }

        $message = 'Items ' . implode(', ', $alreadyShipped) . ' have already been shipped.';

        throw UnprocessableEntityException::forField('items', $message);
    }

    private function persistShipment(SaleOrder $saleOrder, SaleOrderShipmentCreateData $payload, User $user): SaleOrderShipment
    {
        $shipment = SaleOrderShipment::create([
            'sale_order_id' => $saleOrder->id,
            'user_id' => $user->id,
            'store_id' => $saleOrder->store_id,
            'shipment_no' => $this->numberingService->generateShipmentNumber($saleOrder),
            'status_id' => SaleOrderShipmentStatus::OPEN,
            'document_date' => $payload->documentDate,
        ]);

        $shipment->createWholesaleDocument();

        return $shipment;
    }

    private function updateShipmentFields(SaleOrderShipment $shipment, array $payload): void
    {
        $updates = array_intersect_key($payload, array_flip(['notes']));

        if (empty($updates)) {
            return;
        }

        $shipment->update($updates);
    }

    private function syncShipmentItems(SaleOrderShipment $shipment, array $itemIds): void
    {
        $existing = SaleOrderShipmentItem::withTrashed()
            ->where('sale_order_shipment_id', $shipment->id)
            ->get()
            ->keyBy('sale_order_item_id');

        $currentIds = $existing->filter(static fn (SaleOrderShipmentItem $item) => ! $item->trashed())->keys()->all();

        $this->deleteRemovedItems($shipment->id, $currentIds, $itemIds);
        $this->addOrRestoreItems($shipment, $existing, $itemIds);
    }

    private function deleteRemovedItems(int $shipmentId, array $currentIds, array $newIds): void
    {
        $toDelete = array_diff($currentIds, $newIds);

        if (empty($toDelete)) {
            return;
        }

        SaleOrderShipmentItem::where('sale_order_shipment_id', $shipmentId)
            ->whereIn('sale_order_item_id', $toDelete)
            ->delete();
    }

    private function addOrRestoreItems(SaleOrderShipment $shipment, $existing, array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            if (! $existing->has($itemId)) {
                $shipment->items()->create(['sale_order_item_id' => $itemId]);

                continue;
            }

            $item = $existing->get($itemId);
            if ($item->trashed()) {
                $item->restore();
            }
        }
    }

    private function validateShipmentTransition(
        SaleOrderShipment $shipment,
        SaleOrderShipmentStatus $toStatus,
        SaleOrder $saleOrder
    ): void {
        $this->ensureTransitionIsAllowed($shipment, $toStatus);

        if ($toStatus === SaleOrderShipmentStatus::SHIPPED) {
            $this->validateShipmentReadiness($shipment, $saleOrder);
        }
    }

    private function ensureTransitionIsAllowed(SaleOrderShipment $shipment, SaleOrderShipmentStatus $toStatus): void
    {
        $allowedTargets = match ($shipment->status_id) {
            SaleOrderShipmentStatus::OPEN => [SaleOrderShipmentStatus::SHIPPED, SaleOrderShipmentStatus::ARCHIVED],
            default => []
        };

        if (! in_array($toStatus, $allowedTargets, true)) {
            throw new UnprocessableEntityException('Invalid shipment status transition.');
        }
    }

    private function validateShipmentReadiness(SaleOrderShipment $shipment, SaleOrder $saleOrder): void
    {
        if (! $shipment->items()->exists()) {
            $message = 'Cannot ship an empty shipment.';

            throw UnprocessableEntityException::forField('items', $message);
        }

        $itemIds = $shipment->items()->pluck('sale_order_item_id')->all();

        // Lock the items to prevent concurrent shipments from shipping the same items
        // Select only ID to minimize payload since we only need the lock, not the data
        SaleOrderItem::where('sale_order_id', $saleOrder->id)
            ->whereIn('id', $itemIds)
            ->select('id')
            ->lockForUpdate()
            ->get();

        $this->validateItemsNotShipped($saleOrder, $itemIds, $shipment->id);

        $this->ensureOrderHasActiveInvoice($saleOrder, 'Cannot ship order without an active invoice.');
    }

    private function requiresOrderStatusUpdate(
        SaleOrderShipmentStatus $fromStatus,
        SaleOrderShipmentStatus $toStatus
    ): bool {
        return $toStatus === SaleOrderShipmentStatus::SHIPPED || $fromStatus === SaleOrderShipmentStatus::SHIPPED;
    }

    private function updateParentOrderStatus(SaleOrder $saleOrder): void
    {
        if ($saleOrder->status_id === SaleOrderStatus::CANCELLED) {
            return;
        }

        $saleOrder->loadMissing('items.shipments');
        $newStatus = $saleOrder->calculateShipmentStatus();

        if ($saleOrder->status_id === $newStatus) {
            return;
        }

        $saleOrder->update(['status_id' => $newStatus]);
    }

    private function shouldRetryCreate(QueryException $e, int $attempt, int $saleOrderId): bool
    {
        $isUniqueViolation = in_array($e->getCode(), ['23000', '23505'], true);

        if (! $isUniqueViolation || $attempt >= self::MAX_CREATE_ATTEMPTS) {
            return false;
        }

        Log::warning('Shipment number collision, retrying', [
            'sale_order_id' => $saleOrderId,
            'attempt' => $attempt,
        ]);

        return true;
    }
}

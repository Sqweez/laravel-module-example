<?php

namespace App\Service\SaleOrder;

use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderItem;
use Illuminate\Support\Collection;
use RuntimeException;

readonly class SaleOrderItemSyncService
{
    public function syncItems(SaleOrder $saleOrder, array $items): void
    {
        $existingIds = $saleOrder->items()->pluck('id')->toArray();
        $providedIds = array_filter(array_column($items, 'id'));

        $this->validateItemIds($providedIds, $existingIds);
        $this->deleteRemovedItems($existingIds, $providedIds);
        $this->upsertItems($saleOrder, $items);
    }

    public function createItem(SaleOrder $saleOrder, array $data): SaleOrderItem
    {
        if (!isset($data['line_no'])) {
            $data['line_no'] = ($saleOrder->items()->max('line_no') ?? 0) + 1;
        }

        $item = new SaleOrderItem([
            'line_no' => $data['line_no'],
            'variant_id' => $data['variant_id'] ?? null,
            'product_code' => $data['product_code'] ?? null,
            'supplier_code' => $data['supplier_code'] ?? null,
            'product_description' => $data['product_description'],
            'unit_of_measure' => $data['unit_of_measure'] ?? 'Item',
            'quantity' => $data['quantity'],
            'unit_price_cents' => $data['unit_price_cents'],
            'discount_percent' => $data['discount_percent'] ?? 0
        ]);

        $item->calculateTotal();
        $item->saleOrder()->associate($saleOrder);
        $item->save();

        return $item;
    }

    private function validateItemIds(array $providedIds, array $existingIds): void
    {
        $invalidIds = array_diff($providedIds, $existingIds);

        if (!empty($invalidIds)) {
            $message = sprintf(
                'Invalid item IDs provided: %s. Items do not belong to this sale order.',
                implode(', ', $invalidIds)
            );

            throw UnprocessableEntityException::forField('items', $message);
        }
    }

    private function deleteRemovedItems(array $existingIds, array $providedIds): void
    {
        $toDelete = array_diff($existingIds, $providedIds);

        if (empty($toDelete)) {
            return;
        }

        $items = SaleOrderItem::with('shipments')->whereIn('id', $toDelete)->get();
        $lockedItems = $items->filter(static fn (SaleOrderItem $item) => $item->isShipped())->pluck('id')->all();

        if (!empty($lockedItems)) {
            $message = sprintf('Cannot remove shipped items: %s.', implode(', ', $lockedItems));

            throw UnprocessableEntityException::forField('items', $message);
        }

        SaleOrderItem::whereIn('id', $toDelete)->delete();
    }

    private function upsertItems(SaleOrder $saleOrder, array $items): void
    {
        $this->applyTwoPhaseRenumbering($saleOrder, $items);
    }

    private function applyTwoPhaseRenumbering(SaleOrder $saleOrder, array $items): void
    {
        $updates = [];
        $creates = [];

        foreach ($items as $index => $itemData) {
            $finalLineNo = $index + 1;

            if (empty($itemData['id'])) {
                $creates[] = ['data' => $itemData, 'line_no' => $finalLineNo];
            } else {
                $updates[] = ['data' => $itemData, 'line_no' => $finalLineNo];
            }
        }

        if ($updates === []) {
            $this->createNewItems($saleOrder, $creates);

            return;
        }

        $updateIds = array_column(array_column($updates, 'data'), 'id');
        $existingItems = $saleOrder->items()->with('shipments')->whereIn('id', $updateIds)->get()->keyBy('id');

        $this->phaseOneAssignTemporaryLineNumbers($existingItems, $updates);
        $this->phaseTwoAssignFinalLineNumbers($existingItems, $updates);
        $this->createNewItems($saleOrder, $creates);
    }

    /**
     * @param  Collection<int, SaleOrderItem>  $existingItems
     */
    private function phaseOneAssignTemporaryLineNumbers(Collection $existingItems, array $updates): void
    {
        foreach ($updates as $index => $update) {
            $temporaryLineNo = -($index + 1);
            $itemId = $update['data']['id'];

            $item = $existingItems->get($itemId);

            if (!$item) {
                throw new RuntimeException(sprintf('Item ID %d not found during sync (race condition).', $itemId), 500);
            }

            if ($item->isShipped()) {
                $message = 'Cannot modify items that have already been shipped.';

                throw UnprocessableEntityException::forField('items', $message);
            }

            $item->line_no = $temporaryLineNo;
            $item->save();
        }
    }

    /**
     * @param  Collection<int, SaleOrderItem>  $existingItems
     */
    private function phaseTwoAssignFinalLineNumbers(Collection $existingItems, array $updates): void
    {
        foreach ($updates as $update) {
            $itemData = $update['data'];
            $itemData['line_no'] = $update['line_no'];
            $itemId = $itemData['id'];

            $item = $existingItems->get($itemId);

            if (!$item) {
                throw new RuntimeException(
                    sprintf('Item ID %d not found during phase 2 (race condition).', $itemId),
                    500
                );
            }

            $item->fill($this->sanitizeItemDataForUpdate($itemData));
            $item->calculateTotal();
            $item->save();
        }
    }

    private function createNewItems(SaleOrder $saleOrder, array $creates): void
    {
        foreach ($creates as $create) {
            $itemData = $create['data'];
            $itemData['line_no'] = $create['line_no'];
            $this->createItem($saleOrder, $itemData);
        }
    }

    private function sanitizeItemDataForUpdate(array $data): array
    {
        if (array_key_exists('unit_of_measure', $data) && $data['unit_of_measure'] === null) {
            unset($data['unit_of_measure']);
        }
        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] === null) {
            unset($data['discount_percent']);
        }

        return $data;
    }
}

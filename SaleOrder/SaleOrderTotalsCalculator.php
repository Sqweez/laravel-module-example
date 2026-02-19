<?php

namespace App\Service\SaleOrder;

use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderItem;
use App\Exceptions\UnprocessableEntityException;
use Illuminate\Database\Eloquent\Collection;

readonly class SaleOrderTotalsCalculator
{
    public function calculateTotal(int $subtotalCents, int $shippingCents, int $discountCents): int
    {
        $total = $subtotalCents + $shippingCents - $discountCents;

        if ($total < 0) {
            $message = 'Discount cannot exceed subtotal plus shipping';

            throw UnprocessableEntityException::forField('discount', $message);
        }

        return $total;
    }

    public function calculateDiscountCents(int $baseCents, float $discountPercent): int
    {
        return (int) round($baseCents * ($discountPercent / 100), 0, PHP_ROUND_HALF_UP);
    }

    public function computeSubtotal(SaleOrder $saleOrder): int
    {
        return $saleOrder->relationLoaded('items')
            ? $saleOrder->items->sum('total_cents')
            : $saleOrder->items()->sum('total_cents');
    }

    public static function computeItemsSubtotalBeforeDiscounts(Collection $items): int
    {
        return (int) $items->sum(static function (SaleOrderItem $item): int {
            $unitAmount = $item->unit_price_cents ?? 0;
            $quantity = $item->quantity ?? 0.0;

            if ($unitAmount <= 0 || $quantity <= 0.0) {
                return 0;
            }

            return (int) round($unitAmount * $quantity, 0, PHP_ROUND_HALF_UP);
        });
    }

    public static function computeItemsDiscountCents(Collection $items): int
    {
        return (int) $items->sum(static function (SaleOrderItem $item): int {
            $unitAmount = $item->unit_price_cents ?? 0;
            $quantity = $item->quantity ?? 0.0;

            if ($unitAmount <= 0 || $quantity <= 0.0) {
                return 0;
            }

            $lineSubtotal = $unitAmount * $quantity;
            $discountPercent = min((float) ($item->discount_percent ?? 0), 100.0);

            if ($discountPercent <= 0) {
                return 0;
            }

            return (int) round($lineSubtotal * ($discountPercent / 100), 0, PHP_ROUND_HALF_UP);
        });
    }

    public function validateTotal(SaleOrder $saleOrder): void
    {
        $subtotalCents = $this->computeSubtotal($saleOrder);
        $this->calculateTotal($subtotalCents, $saleOrder->shipping_cents, $saleOrder->discount_cents);
    }

    public function buildPreviewItems(array $itemsData): Collection
    {
        $items = new Collection();

        if ($itemsData === []) {
            return $items;
        }

        $lineNo = 1;
        foreach ($itemsData as $itemData) {
            if (!is_array($itemData) || !$this->isCompleteItem($itemData)) {
                continue;
            }

            $item = new SaleOrderItem([
                'line_no' => $lineNo++,
                'variant_id' => $itemData['variant_id'] ?? null,
                'product_code' => $itemData['product_code'] ?? null,
                'supplier_code' => $itemData['supplier_code'] ?? null,
                'product_description' => $itemData['product_description'],
                'unit_of_measure' => $itemData['unit_of_measure'] ?? 'Item',
                'quantity' => $itemData['quantity'],
                'unit_price_cents' => $itemData['unit_price_cents'],
                'discount_percent' => $itemData['discount_percent'] ?? 0,
            ]);
            $item->calculateTotal();
            $items->push($item);
        }

        return $items;
    }

    private function isCompleteItem(array $itemData): bool
    {
        if (!isset($itemData['product_description'], $itemData['quantity'], $itemData['unit_price_cents'])) {
            return false;
        }

        if (!is_string($itemData['product_description']) || trim($itemData['product_description']) === '') {
            return false;
        }

        if (!is_numeric($itemData['quantity']) || (float) $itemData['quantity'] <= 0) {
            return false;
        }

        if (!is_numeric($itemData['unit_price_cents']) || (int) $itemData['unit_price_cents'] < 0) {
            return false;
        }

        return true;
    }
}

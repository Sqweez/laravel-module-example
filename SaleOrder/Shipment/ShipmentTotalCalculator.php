<?php

namespace App\Service\SaleOrder\Shipment;

use App\DTO\Wholesale\WholesaleTotalsDTO;
use App\Models\SaleOrder\SaleOrderShipment;
use App\Models\SaleOrder\SaleOrderShipmentItem;

final readonly class ShipmentTotalCalculator
{
    public static function calculate(SaleOrderShipment $shipment): WholesaleTotalsDTO
    {
        $shipment->loadMissing(['items.saleOrderItem', 'saleOrder']);

        $subtotalCents = (int) $shipment->items->sum(static fn (SaleOrderShipmentItem $item): int => $item->saleOrderItem
            ? (int) ($item->saleOrderItem->quantity * $item->saleOrderItem->unit_price_cents)
            : 0);

        $shippingCents = $shipment->saleOrder->shipping_cents;
        $discountCents = $shipment->saleOrder->discount_cents;
        $totalCents = $subtotalCents + $shippingCents - $discountCents;

        return new WholesaleTotalsDTO(
            subtotalCents: $subtotalCents,
            shippingCents: $shippingCents,
            discountCents: $discountCents,
            totalCents: $totalCents
        );
    }
}

<?php

namespace App\Service\SaleOrder\Documents;

use App\Models\SaleOrder\Contracts\WholesaleDocumentViewable;
use App\Models\SaleOrder\Enums\WholesaleDocumentType;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Models\SaleOrder\SaleOrderPayment;
use App\Models\SaleOrder\SaleOrderShipment;
use App\Models\SaleOrder\WholesaleDocument;
use App\Support\HttpMethods;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

readonly class WholesaleDocumentService
{
    private const array DEFAULT_SELECT = [
        'id',
        'store_id',
        'documentable_type',
        'documentable_id',
        'created_at'
    ];

    public function list(int $storeId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->buildQuery($storeId, $filters)
            ->with($this->documentableEagerLoads());

        return $this->applyDefaultSort($query)->paginate($perPage);
    }

    public function listAsDto(int $storeId, array $filters = [], int $perPage = 15): array
    {
        $paginator = $this->list($storeId, $filters, $perPage);

        $items = $paginator->getCollection()->map(function (WholesaleDocument $doc) {
            /** @var WholesaleDocumentViewable $documentable */
            $documentable = $doc->documentable;
            $documentable->setRelation('wholesaleDocument', $doc);
            return $documentable->toDocumentViewDTO()->toArray();
        });

        return [
            'data' => $items,
            'meta' => HttpMethods::paginationMeta($paginator)
        ];
    }

    public function exportIterator(int $storeId, array $filters = [], int $chunkSize = 500): LazyCollection
    {
        $query = $this->buildQuery($storeId, $filters)
            ->with($this->documentableEagerLoads());

        return $this->applyDefaultSort($query)
            ->lazy($chunkSize)
            ->map(function (WholesaleDocument $doc): ?array {
                /** @var WholesaleDocumentViewable $documentable */
                $documentable = $doc->documentable;
                if (!$documentable) {
                    return null;
                }

                // Reuse the current wholesale document instance for DTO access.
                $documentable->setRelation('wholesaleDocument', $doc);

                return $documentable->toDocumentViewDTO()->toArray();
            })
            ->filter();
    }

    private function buildQuery(int $storeId, array $filters): Builder
    {
        $query = WholesaleDocument::query()
            ->select(self::DEFAULT_SELECT)
            ->whereHas('documentable')
            ->where('store_id', $storeId);

        if (!empty($filters['type'])) {
            $type = WholesaleDocumentType::tryFromName($filters['type']);
            if ($type) {
                $query->whereIn('documentable_type', $this->resolveDocumentableTypeVariants($type));
            }
        }

        if (!empty($filters['sale_order_id'])) {
            $this->applySaleOrderFilter($query, (int) $filters['sale_order_id']);
        }

        return $query;
    }

    private function applyDefaultSort(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string, callable>
     */
    private function documentableEagerLoads(): array
    {
        return [
            'documentable' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    SaleOrder::class => [],
                    SaleOrderInvoice::class => [],
                    SaleOrderPayment::class => ['saleOrder'],
                    SaleOrderShipment::class => ['saleOrder']
                ]);
            }
        ];
    }

    private function applySaleOrderFilter(Builder $query, int $saleOrderId): void
    {
        $query->where(function (Builder $q) use ($saleOrderId) {
            $q->where(function (Builder $sub) use ($saleOrderId) {
                $sub->whereIn(
                    'documentable_type',
                    $this->resolveDocumentableTypeVariants(WholesaleDocumentType::SaleOrder)
                )->where(
                    'documentable_id',
                    $saleOrderId
                );
            })->orWhere(function (Builder $sub) use ($saleOrderId) {
                $sub->whereIn('documentable_type', [
                    ...$this->resolveDocumentableTypeVariants(WholesaleDocumentType::Invoice),
                    ...$this->resolveDocumentableTypeVariants(WholesaleDocumentType::Payment),
                    ...$this->resolveDocumentableTypeVariants(WholesaleDocumentType::Shipment),
                ])->whereHas('documentable', fn (Builder $doc) => $doc->where('sale_order_id', $saleOrderId));
            });
        });
    }

    /**
     * @return array<int, string>
     */
    private function resolveDocumentableTypeVariants(WholesaleDocumentType $type): array
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $type->value;
        $alias = (new $modelClass())->getMorphClass();

        return array_values(array_unique([
            $type->value,
            $alias,
        ]));
    }
}

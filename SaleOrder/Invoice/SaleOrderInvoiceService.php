<?php

namespace App\Service\SaleOrder\Invoice;

use App\DTO\Wholesale\Invoice\InvoiceCreateData;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\User;
use Throwable;

/**
 * Facade for invoice operations. Delegates to specialized workflow services.
 */
readonly class SaleOrderInvoiceService
{
    public function __construct(
        private SaleOrderInvoiceGenerateService $generateService,
        private SaleOrderInvoiceCreateService $createService,
        private SaleOrderInvoiceUpdateService $updateService,
        private SaleOrderInvoiceArchiveService $archiveService
    ) {
    }

    /**
     * @throws Throwable
     */
    public function createInvoice(SaleOrder $order, InvoiceCreateData $data, User $user): SaleOrderInvoice
    {
        return $this->createService->create($order, $data, $user);
    }

    /**
     * @throws Throwable
     */
    public function generateFromSaleOrder(int $saleOrderId, User $user): SaleOrderInvoice
    {
        return $this->generateService->generate($saleOrderId, $user);
    }

    /**
     * @throws Throwable
     */
    public function updateInvoice(int $id, array $payload, User $user): SaleOrderInvoice
    {
        return $this->updateService->update($id, $payload, $user);
    }

    /**
     * @throws Throwable
     */
    public function archiveInvoice(int $id, User $user): SaleOrderInvoice
    {
        return $this->archiveService->archive($id, $user);
    }
}

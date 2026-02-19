<?php

namespace Tests\Unit\Service\SaleOrder;

use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Models\SaleOrder\SaleOrderPayment;
use App\Service\SaleOrder\SaleOrderAbilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SaleOrderAbilityServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function can_create_payments_requires_non_draft_and_invoices(): void
    {
        $draftOrder = SaleOrder::factory()->draft()->create();
        $this->invoice($draftOrder, 10_000, SaleOrderInvoiceStatus::ACTIVE);

        $completedOrder = SaleOrder::factory()->completed()->create();
        $this->invoice($completedOrder, 10_000, SaleOrderInvoiceStatus::ACTIVE);

        self::assertFalse($this->abilities($draftOrder)['can_create_payments']);
        self::assertTrue($this->abilities($completedOrder)['can_create_payments']);
    }

    #[Test]
    public function can_create_positive_payments_depends_on_active_invoice_balances(): void
    {
        $saleOrder = SaleOrder::factory()->open()->create();

        $invoiceOne = $this->invoice($saleOrder, 10_000, SaleOrderInvoiceStatus::ACTIVE);
        $invoiceTwo = $this->invoice($saleOrder, 20_000, SaleOrderInvoiceStatus::ACTIVE);

        $this->payment($saleOrder, $invoiceOne, 10_000);
        $this->payment($saleOrder, $invoiceTwo, 5_000);

        self::assertTrue($this->abilities($saleOrder)['can_create_positive_payments']);

        $this->payment($saleOrder, $invoiceTwo, 15_000);

        self::assertFalse($this->abilities($saleOrder)['can_create_positive_payments']);
    }

    #[Test]
    public function can_create_positive_payments_false_when_no_active_invoices(): void
    {
        $saleOrder = SaleOrder::factory()->open()->create();
        $this->invoice($saleOrder, 10_000, SaleOrderInvoiceStatus::ARCHIVED);

        self::assertFalse($this->abilities($saleOrder)['can_create_positive_payments']);
    }

    #[Test]
    public function can_create_refunds_requires_net_paid_amounts(): void
    {
        $saleOrder = SaleOrder::factory()->open()->create();
        $invoice = $this->invoice($saleOrder, 10_000, SaleOrderInvoiceStatus::ACTIVE);

        self::assertFalse($this->abilities($saleOrder)['can_create_refunds']);

        $this->payment($saleOrder, $invoice, 5_000);

        self::assertTrue($this->abilities($saleOrder)['can_create_refunds']);

        $this->payment($saleOrder, $invoice, -5_000);

        self::assertFalse($this->abilities($saleOrder)['can_create_refunds']);
    }

    #[Test]
    public function can_create_shipments_requires_active_invoice(): void
    {
        $saleOrder = SaleOrder::factory()->open()->create();

        self::assertFalse($this->abilities($saleOrder)['can_create_shipments']);

        $this->invoice($saleOrder, 10_000, SaleOrderInvoiceStatus::ACTIVE);

        self::assertTrue($this->abilities($saleOrder)['can_create_shipments']);
    }

    private function abilities(SaleOrder $saleOrder): array
    {
        $saleOrder->refresh();

        return (new SaleOrderAbilityService())->collect($saleOrder);
    }

    private function invoice(SaleOrder $saleOrder, int $totalCents, SaleOrderInvoiceStatus $status): SaleOrderInvoice
    {
        /** @var SaleOrderInvoice $invoice */
        $invoice = SaleOrderInvoice::factory()->create([
            'sale_order_id' => $saleOrder->id,
            'status_id' => $status,
            'total_cents' => $totalCents,
        ]);

        return $invoice;
    }

    private function payment(SaleOrder $saleOrder, SaleOrderInvoice $invoice, int $amountCents): SaleOrderPayment
    {
        /** @var SaleOrderPayment $payment */
        $payment = SaleOrderPayment::factory()->create([
            'sale_order_id' => $saleOrder->id,
            'sale_order_invoice_id' => $invoice->id,
            'amount_cents' => $amountCents,
        ]);

        return $payment;
    }
}

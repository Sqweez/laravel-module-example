<?php

namespace Tests\Unit\Service\SaleOrder;

use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Models\SaleOrder\SaleOrderPayment;
use App\Service\SaleOrder\Payment\SaleOrderInvoicePaymentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SaleOrderInvoicePaymentValidatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function blocks_positive_payment_when_all_active_invoices_are_paid(): void
    {
        $saleOrder = $this->openSaleOrder();

        $invoiceOne = $this->activeInvoice($saleOrder, 10_000);
        $invoiceTwo = $this->activeInvoice($saleOrder, 20_000);

        $this->payment($saleOrder, $invoiceOne, 10_000);
        $this->payment($saleOrder, $invoiceTwo, 20_000);

        $invoiceOne->refresh();
        $errors = $this->validator()->validatePaymentAmount($invoiceOne, 1_000);

        self::assertSame([
            SaleOrderInvoicePaymentValidator::ALL_INVOICES_PAID_MESSAGE,
        ], $errors);
    }

    #[Test]
    public function allows_positive_payment_when_any_active_invoice_has_remaining_balance(): void
    {
        $saleOrder = $this->openSaleOrder();

        $invoiceOne = $this->activeInvoice($saleOrder, 10_000);
        $invoiceTwo = $this->activeInvoice($saleOrder, 20_000);

        $this->payment($saleOrder, $invoiceOne, 3_000);
        $this->payment($saleOrder, $invoiceTwo, 20_000);

        $invoiceOne->refresh();
        $errors = $this->validator()->validatePaymentAmount($invoiceOne, 1_000);

        self::assertSame([], $errors);
    }

    #[Test]
    public function allows_refunds_even_when_all_active_invoices_are_paid(): void
    {
        $saleOrder = $this->openSaleOrder();

        $invoice = $this->activeInvoice($saleOrder, 10_000);
        $this->payment($saleOrder, $invoice, 10_000);

        $invoice->refresh();
        $errors = $this->validator()->validatePaymentAmount($invoice, -500);

        self::assertSame([], $errors);
    }

    #[Test]
    public function ignores_soft_deleted_payments_when_checking_if_all_invoices_are_paid(): void
    {
        $saleOrder = $this->openSaleOrder();

        $invoice = $this->activeInvoice($saleOrder, 10_000);

        $payment = $this->payment($saleOrder, $invoice, 10_000);
        $payment->delete();

        $invoice->refresh();
        $errors = $this->validator()->validatePaymentAmount($invoice, 1_000);

        self::assertSame([], $errors);
    }

    private function validator(): SaleOrderInvoicePaymentValidator
    {
        return new SaleOrderInvoicePaymentValidator();
    }

    private function openSaleOrder(): SaleOrder
    {
        /** @var SaleOrder $saleOrder */
        $saleOrder = SaleOrder::factory()->open()->create();

        return $saleOrder;
    }

    private function activeInvoice(SaleOrder $saleOrder, int $totalCents): SaleOrderInvoice
    {
        /** @var SaleOrderInvoice $invoice */
        $invoice = SaleOrderInvoice::factory()->active()->create([
            'sale_order_id' => $saleOrder->id,
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

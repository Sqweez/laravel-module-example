<?php

namespace App\Service\SaleOrder\Payment;

use App\DTO\Wholesale\Payment\PaymentCreateData;
use App\Exceptions\UnprocessableEntityException;
use App\Models\SaleOrder\Enums\SaleOrderInvoiceStatus;
use App\Models\SaleOrder\SaleOrder;
use App\Models\SaleOrder\SaleOrderInvoice;
use App\Models\SaleOrder\SaleOrderPayment;
use App\Service\Numbering\SaleOrderPaymentNumberingService;
use App\Service\SaleOrder\Bookkeeping\SaleOrderBookkeepingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

readonly class SaleOrderPaymentService
{
    public function __construct(
        private SaleOrderPaymentNumberingService $numberingService,
        private SaleOrderInvoicePaymentValidator $validator,
        private SaleOrderBookkeepingService $saleOrderBookkeepingService
    ) {
    }

    public function listPayments(SaleOrder $saleOrder): Collection
    {
        return $saleOrder
            ->payments()
            ->with(['saleOrderInvoice:id,sale_order_invoice_no,status_id'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @throws Throwable
     */
    public function createPayment(SaleOrder $saleOrder, PaymentCreateData $payload): SaleOrderPayment
    {
        return DB::transaction(function () use ($saleOrder, $payload) {
            $lockedSaleOrder = $this->lockSaleOrder($saleOrder);
            $this->ensureInvoiceBelongsToOrder($lockedSaleOrder, $payload->saleOrderInvoice);
            $this->validateInvoiceStatus($payload->saleOrderInvoice, $payload->amountCents);
            $this->validatePaymentLimits($lockedSaleOrder, $payload->saleOrderInvoice, $payload->amountCents);

            $payment = $this->persistPayment($lockedSaleOrder, $payload);
            $this->saleOrderBookkeepingService->recordPayment($payment);

            return $payment;
        });
    }

    public function getPaymentForOrder(SaleOrder $saleOrder, SaleOrderPayment $payment): SaleOrderPayment
    {
        if ($payment->sale_order_id !== $saleOrder->id) {
            throw new NotFoundHttpException('Payment not found for this sale order.');
        }

        $payment->load([
            'saleOrder:id,user_id,store_id',
            'saleOrderInvoice' => static function ($query) {
                $query->with([
                    'payments' => static function ($p) {
                        $p->select('id', 'sale_order_id', 'sale_order_invoice_id', 'amount_cents', 'is_deposit');
                    },
                ]);
            },
            'bookkeepingJournalEntries.lines',
        ]);

        return $payment;
    }

    /**
     * @throws Throwable
     */
    public function deletePayment(SaleOrder $saleOrder, SaleOrderPayment $payment): void
    {
        DB::transaction(function () use ($saleOrder, $payment) {
            $this->ensurePaymentBelongsToOrder($saleOrder, $payment);
            $this->saleOrderBookkeepingService->recordPaymentDeletionReversal($payment);

            $payment->delete();
        });
    }

    private function lockSaleOrder(SaleOrder $saleOrder): SaleOrder
    {
        $locked = SaleOrder::where('id', $saleOrder->id)->lockForUpdate()->first();

        if (! $locked) {
            throw new NotFoundHttpException('Sale order not found');
        }

        return $locked;
    }

    /**
     * Validates invoice status for the payment type.
     *
     * Note: This validation is intentionally duplicated in both the FormRequest
     * (pre-lock) and Service (post-lock) to prevent race conditions.
     */
    private function validateInvoiceStatus(SaleOrderInvoice $invoice, int $amountCents): void
    {
        if ($amountCents > 0 && $invoice->status_id === SaleOrderInvoiceStatus::ARCHIVED) {
            throw UnprocessableEntityException::forField(
                'sale_order_invoice_id',
                'Cannot add positive payments to an archived invoice.'
            );
        }
    }

    private function ensureInvoiceBelongsToOrder(SaleOrder $saleOrder, SaleOrderInvoice $invoice): void
    {
        if ($invoice->sale_order_id === $saleOrder->id) {
            return;
        }

        throw UnprocessableEntityException::forField(
            'sale_order_invoice_id',
            'Linked invoice does not belong to this sale order.'
        );
    }

    /**
     * Validates payment amount limits against sale order totals.
     *
     * Note: This validation is intentionally duplicated in both the FormRequest
     * (pre-lock) and Service (post-lock) to prevent race conditions while providing
     * fast-fail UX feedback.
     */
    private function validatePaymentLimits(SaleOrder $saleOrder, SaleOrderInvoice $invoice, int $newAmount): void
    {
        $errors = $this->validator->validatePaymentAmount($invoice, $newAmount, $saleOrder);

        if (! empty($errors)) {
            $message = $errors[0] ?? 'Invalid payment amount.';

            throw new UnprocessableEntityException($message, ['amount_cents' => $errors]);
        }
    }

    private function persistPayment(SaleOrder $saleOrder, PaymentCreateData $payload): SaleOrderPayment
    {
        $amountCents = $payload->amountCents;

        $isDeposit = ! ($amountCents < 0) && ($payload->isDeposit ?? false);

        $payment = $payload
            ->saleOrderInvoice
            ->payments()
            ->create([
                'sale_order_id' => $saleOrder->id,
                'store_id' => $saleOrder->store_id,
                'payment_no' => $this->numberingService->generatePaymentNumber($saleOrder),
                'amount_cents' => $amountCents,
                'is_deposit' => $isDeposit,
                'payment_method' => $payload->paymentMethod,
            ]);

        $payment->createWholesaleDocument();

        return $payment;
    }

    private function ensurePaymentBelongsToOrder(SaleOrder $saleOrder, SaleOrderPayment $payment): void
    {
        if ($payment->sale_order_id !== $saleOrder->id) {
            throw new NotFoundHttpException('Payment not found for this sale order.');
        }
    }
}

<?php

namespace App\Service\SaleOrder\Invoice;

use App\Models\SaleOrder\SaleOrderInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class SaleOrderInvoicePdfService
{
    private const string LOGO_COLLECTION = 'company_logo';
    private const float LOGO_MAX_WIDTH_POINTS = 150.0;
    private const float LOGO_MAX_HEIGHT_POINTS = 45.0;
    private const float PIXELS_TO_POINTS = 0.75;
    private const string VIEW = 'pdf.sale-order-invoices.pdf';

    public function generate(SaleOrderInvoice $invoice): string
    {
        $this->loadInvoiceRelations($invoice);
        $logo = $this->resolveLogoData($invoice);

        $pdfView =  Pdf::loadView(self::VIEW, [
            'invoice' => $invoice,
            'logoDataUri' => $logo['dataUri'],
            'logoWidth' => $logo['width'],
            'logoHeight' => $logo['height'],
        ]);

        $pdfView->setPaper('A4');
        $pdfView->setOption('dpi', 150);
        return $pdfView->output();
    }

    public function view(SaleOrderInvoice $invoice): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $this->loadInvoiceRelations($invoice);
        $logo = $this->resolveLogoData($invoice);

        return view(self::VIEW, [
            'invoice' => $invoice,
            'logoDataUri' => $logo['dataUri'],
            'logoWidth' => $logo['width'],
            'logoHeight' => $logo['height'],
        ]);
    }

    private function loadInvoiceRelations(SaleOrderInvoice $invoice): void
    {
        $invoice->load([
            'items' => static fn ($q) => $q->orderBy('line_no'),
            'saleOrder' => static fn ($q) => $q->withTrashed()->with([
                'store' => static fn ($storeQuery) => $storeQuery->with([
                    'media' => static fn ($mediaQuery) => $mediaQuery->where('collection_name', self::LOGO_COLLECTION),
                ]),
            ]),
        ]);
    }

    /**
     * @return array{dataUri: ?string, width: ?string, height: ?string}
     */
    private function resolveLogoData(SaleOrderInvoice $invoice): array
    {
        $store = $invoice->saleOrder?->store;

        if (! $store instanceof HasMedia) {
            return [
                'dataUri' => null,
                'width' => null,
                'height' => null,
            ];
        }

        $media = $this->firstLogoMedia($store);

        if (! $media instanceof Media) {
            return [
                'dataUri' => null,
                'width' => null,
                'height' => null,
            ];
        }

        $dimensions = $this->resolveLogoDimensions($media);
        $diskName = $media->disk;
        $path = $media->getPathRelativeToRoot();

        try {
            $disk = Storage::disk($diskName);

            $dataUri = $this->readAsDataUri($disk, $path, $media);

            if ($dataUri === null) {
                return [
                    'dataUri' => null,
                    'width' => null,
                    'height' => null,
                ];
            }

            return [
                'dataUri' => $dataUri,
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ];
        } catch (Throwable $exception) {
            $this->logReadFailure($invoice, $media, $exception);

            return [
                'dataUri' => null,
                'width' => null,
                'height' => null,
            ];
        }
    }

    private function firstLogoMedia(HasMedia $store): ?Media
    {
        $media = $store->getFirstMedia(self::LOGO_COLLECTION);

        return $media instanceof Media ? $media : null;
    }

    /**
     * @return array{width: ?string, height: ?string}
     */
    private function resolveLogoDimensions(Media $media): array
    {
        $width = $this->normalizeLogoDimension($media->getCustomProperty('width'));
        $height = $this->normalizeLogoDimension($media->getCustomProperty('height'));

        if ($width === null || $height === null) {
            return [
                'width' => null,
                'height' => null,
            ];
        }

        $widthPoints = $width * self::PIXELS_TO_POINTS;
        $heightPoints = $height * self::PIXELS_TO_POINTS;

        $scale = min(
            1,
            self::LOGO_MAX_WIDTH_POINTS / $widthPoints,
            self::LOGO_MAX_HEIGHT_POINTS / $heightPoints,
        );

        return [
            'width' => $this->formatPoints($widthPoints * $scale),
            'height' => $this->formatPoints($heightPoints * $scale),
        ];
    }

    private function normalizeLogoDimension(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $dimension = (int) round((float) $value);

        return $dimension > 0 ? $dimension : null;
    }

    private function formatPoints(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function readAsDataUri(Filesystem $disk, string $path, Media $media): ?string
    {
        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);
        $mime = $disk->mimeType($path) ?: (string) $media->mime_type;

        return sprintf('data:%s;base64,%s', $mime, base64_encode($contents));
    }

    private function logReadFailure(
        SaleOrderInvoice $invoice,
        Media $media,
        Throwable $exception,
    ): void {
        Log::warning('Failed to read company logo for SOI PDF', [
            'invoice_id' => $invoice->id,
            'store_id' => $invoice->saleOrder?->store?->id,
            'media_id' => $media->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

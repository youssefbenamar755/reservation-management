<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class SubmissionItineraryPdf
{
    /**
     * Render a normalized itinerary as PDF bytes without storing the document.
     *
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsFontSubsettingEnabled(true);

        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml(view('pdf.submission-itinerary', [
            'document' => $document,
            'logoDataUri' => $this->logoDataUri($document['website']['logo_data_uri'] ?? null),
        ])->render(), 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(
            $canvas->get_width() - 102,
            $canvas->get_height() - 28,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7,
            [0.40, 0.45, 0.51],
        );

        return $pdf->output();
    }

    private function logoDataUri(mixed $value): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        // The brand loader supplies a local raster image. Never let document
        // fields become remote, filesystem, or SVG resources in the renderer.
        if (! is_string($value) || strlen($value) > 3 * 1024 * 1024
            || ! preg_match('/\Adata:image\/(png|jpeg);base64,([A-Za-z0-9+\/\r\n=]+)\z/', $value, $matches)) {
            return null;
        }

        $bytes = base64_decode($matches[2], true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        $expectedType = $matches[1] === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        return $image !== false && $image[2] === $expectedType ? $value : null;
    }
}

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
            'airlineLogoDataUris' => $this->airlineLogos($document),
            'flightIconDataUris' => [
                'outbound' => $this->localImage(resource_path('images/itinerary/outbound.png')),
                'return' => $this->localImage(resource_path('images/itinerary/return.png')),
            ],
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

    private function airlineLogos(array $document): array
    {
        $manifestPath = resource_path('images/airlines/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        $logos = [];
        foreach ($document['trips'] ?? [] as $trip) {
            foreach ($trip['segments'] ?? [] as $segment) {
                $code = strtoupper(trim((string) ($segment['carrier_code'] ?? '')));
                if (! preg_match('/\A[A-Z0-9]{2}\z/D', $code) || isset($logos[$code])) {
                    continue;
                }
                // Both the carrier key and filename must match the packaged catalog.
                // Neither submitted URLs nor airline-name guesses become image sources.
                if (($manifest[$code] ?? null) === $code.'.png') {
                    $logo = $this->localImage(resource_path('images/airlines/'.$code.'.png'));
                    if ($logo !== null) {
                        $logos[$code] = $logo;
                    }
                }
            }
        }

        return $logos;
    }

    private function localImage(string $path): ?string
    {
        if (! extension_loaded('gd') || ! is_file($path) || filesize($path) > 1000000) {
            return null;
        }
        $bytes = file_get_contents($path);
        $image = @getimagesizefromstring($bytes);
        if ($image === false || $image[2] !== IMAGETYPE_PNG || $image[0] > 2000 || $image[1] > 2000) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}

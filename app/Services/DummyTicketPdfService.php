<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DummyTicketPdfService
{
    /**
     * Generate a dummy ticket PDF
     *
     * @param array $data ['passengers' => [], 'route' => ['origin' => '', 'destination' => ''], 'dates' => ['departure' => '', 'return' => ''], 'pnr' => '']
     * @return string File path relative to storage
     */
    public function generatePdf(array $data): string
    {
        try {
            $passengers = $data['passengers'] ?? [];
            $route = $data['route'] ?? [];
            $dates = $data['dates'] ?? [];
            $pnr = $data['pnr'] ?? 'N/A';

            // Generate HTML for PDF
            $html = $this->generateHtml($passengers, $route, $dates, $pnr);

            // Configure Dompdf
            $options = new Options();
            $options->setIsHtml5ParserEnabled(true);
            $options->setIsRemoteEnabled(true);
            $options->setDefaultFont('Arial');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Generate filename
            $filename = 'tickets/' . date('Y/m/') . 'ticket_' . $pnr . '_' . time() . '.pdf';
            
            // Ensure directory exists
            $directory = storage_path('app/public/' . dirname($filename));
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Save PDF
            $fullPath = storage_path('app/public/' . $filename);
            file_put_contents($fullPath, $dompdf->output());

            // Return path relative to storage/app/public
            return $filename;
        } catch (\Exception $e) {
            Log::error('Failed to generate ticket PDF', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw new \RuntimeException('Failed to generate ticket PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate HTML for the ticket
     */
    private function generateHtml(array $passengers, array $route, array $dates, string $pnr): string
    {
        $origin = $route['origin'] ?? 'N/A';
        $destination = $route['destination'] ?? 'N/A';
        $departureDate = $dates['departure'] ?? 'N/A';
        $returnDate = $dates['return'] ?? null;

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
            padding: 20px;
            background: #f5f5f5;
        }
        .ticket {
            background: white;
            border: 2px solid #333;
            border-radius: 8px;
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 28px;
            color: #1a1a1a;
            margin-bottom: 10px;
        }
        .header .subtitle {
            font-size: 14px;
            color: #666;
        }
        .pnr-section {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 25px;
            text-align: center;
        }
        .pnr-section .label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .pnr-section .value {
            font-size: 24px;
            font-weight: bold;
            color: #1a1a1a;
            letter-spacing: 3px;
        }
        .route-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px;
            background: #f9f9f9;
            border-radius: 5px;
            margin-bottom: 25px;
        }
        .route-item {
            text-align: center;
            flex: 1;
        }
        .route-item .code {
            font-size: 32px;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 5px;
        }
        .route-item .label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }
        .arrow {
            font-size: 24px;
            color: #666;
            margin: 0 20px;
        }
        .dates-section {
            display: flex;
            justify-content: space-around;
            margin-bottom: 25px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .date-item {
            text-align: center;
        }
        .date-item .label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .date-item .value {
            font-size: 16px;
            font-weight: bold;
            color: #1a1a1a;
        }
        .passengers-section {
            margin-top: 30px;
        }
        .passengers-section h2 {
            font-size: 18px;
            color: #1a1a1a;
            margin-bottom: 15px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .passenger-item {
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #333;
        }
        .passenger-item .name {
            font-size: 16px;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        .passenger-item .details {
            font-size: 12px;
            color: #666;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #333;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        .disclaimer {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-size: 11px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="header">
            <h1>FLIGHT TICKET</h1>
            <div class="subtitle">Electronic Ticket Confirmation</div>
        </div>

        <div class="pnr-section">
            <div class="label">Confirmation Number</div>
            <div class="value">' . htmlspecialchars($pnr) . '</div>
        </div>

        <div class="route-section">
            <div class="route-item">
                <div class="code">' . htmlspecialchars($origin) . '</div>
                <div class="label">Origin</div>
            </div>
            <div class="arrow">→</div>
            <div class="route-item">
                <div class="code">' . htmlspecialchars($destination) . '</div>
                <div class="label">Destination</div>
            </div>
        </div>

        <div class="dates-section">
            <div class="date-item">
                <div class="label">Departure Date</div>
                <div class="value">' . htmlspecialchars($this->formatDateForDisplay($departureDate)) . '</div>
            </div>';

        if ($returnDate) {
            $html .= '
            <div class="date-item">
                <div class="label">Return Date</div>
                <div class="value">' . htmlspecialchars($this->formatDateForDisplay($returnDate)) . '</div>
            </div>';
        }

        $html .= '
        </div>

        <div class="passengers-section">
            <h2>Passengers</h2>';

        foreach ($passengers as $index => $passenger) {
            $firstName = htmlspecialchars($passenger['firstName'] ?? $passenger['first_name'] ?? 'N/A');
            $lastName = htmlspecialchars($passenger['lastName'] ?? $passenger['last_name'] ?? 'N/A');
            $dateOfBirth = htmlspecialchars($passenger['dateOfBirth'] ?? $passenger['date_of_birth'] ?? 'N/A');
            
            $html .= '
            <div class="passenger-item">
                <div class="name">' . $firstName . ' ' . $lastName . '</div>
                <div class="details">
                    <strong>Date of Birth:</strong> ' . $this->formatDateForDisplay($dateOfBirth) . '
                </div>
            </div>';
        }

        $html .= '
        </div>

        <div class="disclaimer">
            <strong>NOTE:</strong> This is a dummy ticket generated for testing purposes only. 
            This ticket is not valid for travel and cannot be used for actual flight booking.
        </div>

        <div class="footer">
            <p>Generated on ' . date('F j, Y \a\t g:i A') . '</p>
            <p>This is a test ticket - Not valid for travel</p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Format date for display
     */
    private function formatDateForDisplay(?string $date): string
    {
        if (empty($date) || $date === 'N/A') {
            return 'N/A';
        }

        try {
            $dateObj = new \DateTime($date);
            return $dateObj->format('F j, Y');
        } catch (\Exception $e) {
            return $date;
        }
    }
}


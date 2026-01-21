<?php

namespace App\Http\Controllers;

use App\Models\FfSubmission;
use App\Models\Website;
use App\Models\FfForm;
use App\Services\AmadeusDummyTicketGeneratorService;
use App\Services\FluentFormSchemaService;
use App\Services\PnrGenerationService;
use App\Services\DummyTicketPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FfSubmissionController extends Controller
{
    /**
     * Display forms grouped by website_id + form_id
     */
    public function index(Request $request)
    {
        // Group submissions by website_id + form_id
        $forms = FfSubmission::query()
            ->select([
                'website_id',
                'form_id',
                DB::raw('MAX(created_at_wp) as latest_submission_date'),
                DB::raw('COUNT(*) as entry_count'),
                DB::raw('(SELECT email FROM ff_submissions fs2 WHERE fs2.website_id = ff_submissions.website_id AND fs2.form_id = ff_submissions.form_id AND fs2.email IS NOT NULL ORDER BY fs2.created_at_wp DESC LIMIT 1) as latest_email'),
            ])
            ->with('website:id,name')
            ->groupBy('website_id', 'form_id')
            ->when($request->website_id, fn ($q) =>
                $q->where('website_id', $request->website_id)
            )
            ->orderByDesc('latest_submission_date')
            ->paginate(15)
            ->withQueryString();

        // Get form names from cached FfForm table (fast, no HTTP requests)
        $formNamesMap = [];
        
        // Try to get cached form names from database
        try {
            $cachedForms = FfForm::whereIn('website_id', $forms->pluck('website_id')->unique())
                ->whereIn('form_id', $forms->pluck('form_id')->unique())
                ->get(['website_id', 'form_id', 'title']);
            
            // Map cached form names
            foreach ($cachedForms as $cachedForm) {
                $key = "{$cachedForm->website_id}_{$cachedForm->form_id}";
                $formNamesMap[$key] = $cachedForm->title ?? "Form #{$cachedForm->form_id}";
            }
        } catch (\Throwable $e) {
            Log::debug('Could not fetch cached form names', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Add form names to results
        $forms->getCollection()->transform(function ($form) use ($formNamesMap) {
            $key = "{$form->website_id}_{$form->form_id}";
            $form->form_name = $formNamesMap[$key] ?? "Form #{$form->form_id}";
            return $form;
        });

        return Inertia::render('Submissions/Index', [
            'forms' => $forms,
            'websites' => Website::select('id', 'name')->get(),
            'filters' => $request->only(['website_id']),
        ]);
    }

    /**
     * Get available forms for a website (for sync modal)
     */
    public function getFormsForWebsite(Website $website)
    {
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            return response()->json(['error' => 'Fluent Forms credentials not configured'], 400);
        }

        try {
            $baseUrl = rtrim($website->base_url, '/');
            $endpoint = "{$baseUrl}/wp-json/fluentform/v1/forms";

            $response = Http::timeout(10)
                ->withBasicAuth($website->ff_username, $website->ff_app_password)
                ->acceptJson()
                ->get($endpoint);

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch forms'], $response->status());
            }

            $formsData = $response->json();
            $forms = [];

            if (is_array($formsData)) {
                foreach ($formsData as $form) {
                    if (is_numeric($form)) {
                        $forms[] = ['id' => (int) $form, 'title' => "Form #{$form}"];
                    } elseif (is_array($form) && isset($form[0]) && is_array($form[0])) {
                        foreach ($form as $nestedForm) {
                            if (isset($nestedForm['id'])) {
                                $forms[] = [
                                    'id' => (int) $nestedForm['id'],
                                    'title' => $nestedForm['title'] ?? "Form #{$nestedForm['id']}"
                                ];
                            }
                        }
                    } elseif (is_array($form) && isset($form['id'])) {
                        $forms[] = [
                            'id' => (int) $form['id'],
                            'title' => $form['title'] ?? "Form #{$form['id']}"
                        ];
                    }
                }
            }

            return response()->json(['forms' => $forms]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch forms', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display entries (submissions) for a specific form
     */
    public function formEntries(Website $website, int $formId)
    {
        $entries = FfSubmission::query()
            ->where('website_id', $website->id)
            ->where('form_id', $formId)
            ->latest('created_at_wp')
            ->paginate(15)
            ->withQueryString();

        // Get form name from cache only - NO blocking HTTP calls
        $formName = "Form #{$formId}";
        
        $cachedForm = FfForm::where('website_id', $website->id)
            ->where('form_id', $formId)
            ->first();
            
        if ($cachedForm && $cachedForm->title) {
            $formName = $cachedForm->title;
        }

        return Inertia::render('Submissions/FormEntries', [
            'entries' => $entries,
            'website' => $website->only('id', 'name'),
            'formId' => $formId,
            'formName' => $formName,
        ]);
    }

    /**
     * Display entry details
     */
    public function entryDetails(FfSubmission $entry)
    {
        // Load form schema if available
        $formSchema = FfForm::where('website_id', $entry->website_id)
            ->where('form_id', $entry->form_id)
            ->first();

        return Inertia::render('Submissions/EntryDetails', [
            'entry' => $entry->load('website'),
            'formSchema' => $formSchema ? [
                'fields' => $formSchema->fields,
            ] : null,
        ]);
    }

    /**
     * Sync form schema for a specific form and fetch new entries
     */
    public function syncFormSchema(Website $website, int $formId, FluentFormSchemaService $schemaService, \App\Services\FluentFormSubmissionService $submissionService)
    {
        set_time_limit(120); // Increase timeout to 2 minutes for this request
        try {
            // First, sync the form schema
            $ffForm = $schemaService->syncFormSchema($website, $formId);
            
            if (!$ffForm) {
                return back()->with('error', 'Failed to sync form schema. Please check credentials and form ID.');
            }

            // HYBRID SYNC: Sync page 1 synchronously for immediate feedback
            $result = $submissionService->syncPage($website, $formId, 1);
            $newEntriesCount = $result['count'];
            $hasMore = $result['has_more'];

            $message = "Form schema synced. Found {$newEntriesCount} new entries.";

            // If there are more pages, dispatch background job starting from page 2
            if ($hasMore) {
                \App\Jobs\SyncFluentFormEntries::dispatch($website, $formId, 2);
                $message .= ' Synchronization continuing in background.';
            }
            
            return back()->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Failed to sync form schema', [
                'website_id' => $website->id,
                'form_id' => $formId,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Failed to sync form schema: ' . $e->getMessage());
        }
    }



    /**
     * Sync form schema for all forms in a website
     */
    public function syncAllFormSchemas(Website $website, FluentFormSchemaService $service)
    {
        set_time_limit(300); // Increase timeout to 5 minutes for bulk sync
        if (empty($website->ff_username) || empty($website->ff_app_password)) {
            return back()->with('error', 'Fluent Forms credentials not configured for this website.');
        }

        try {
            $baseUrl = rtrim($website->base_url, '/');
            $endpoint = "{$baseUrl}/wp-json/fluentform/v1/forms";

            $response = Http::timeout(10)
                ->withBasicAuth($website->ff_username, $website->ff_app_password)
                ->acceptJson()
                ->get($endpoint);

            if (!$response->successful()) {
                return back()->with('error', 'Failed to fetch forms list.');
            }

            $formsData = $response->json();
            $syncedCount = 0;
            $failedCount = 0;

            if (is_array($formsData)) {
                foreach ($formsData as $form) {
                    $formId = null;
                    
                    if (is_numeric($form)) {
                        $formId = (int) $form;
                    } elseif (is_array($form) && isset($form['id'])) {
                        $formId = (int) $form['id'];
                    } elseif (is_array($form) && isset($form[0]) && is_array($form[0])) {
                        foreach ($form as $nestedForm) {
                            if (isset($nestedForm['id'])) {
                                $formId = (int) $nestedForm['id'];
                                break;
                            }
                        }
                    }
                    
                    if ($formId) {
                        $result = $service->syncFormSchema($website, $formId);
                        if ($result) {
                            // Sync entries in background
                            \App\Jobs\SyncFluentFormEntries::dispatch($website, $formId); 
                            $syncedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                }
            }

            return back()->with('success', "Synced {$syncedCount} form(s). {$failedCount} failed.");
        } catch (\Throwable $e) {
            Log::error('Failed to sync all form schemas', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);
            
            return back()->with('error', 'Failed to sync form schemas: ' . $e->getMessage());
        }
    }

    /**
     * Delete an entry
     */
    public function destroy(FfSubmission $entry)
    {
        $entryId = $entry->entry_id;
        $formId = $entry->form_id;
        $websiteId = $entry->website_id;
        $entry->delete();

        return redirect()->route('submissions.form-entries', ['website' => $websiteId, 'form_id' => $formId])
            ->with('success', "Entry #{$entryId} deleted successfully.");
    }

    /**
     * Delete all submissions for a form
     */
    public function destroyAll(Website $website, int $formId)
    {
        $deletedCount = FfSubmission::where('website_id', $website->id)
            ->where('form_id', $formId)
            ->delete();

        return redirect()->route('submissions.index')
            ->with('success', "Deleted {$deletedCount} submission(s) for Form #{$formId}.");
    }

    /**
     * Generate Amadeus dummy ticket command block for an entry
     */
    public function generateAmadeusCode(FfSubmission $entry, AmadeusDummyTicketGeneratorService $service)
    {
        try {
            $payload = $entry->payload ?? [];
            $response = $payload['response'] ?? [];

            // Handle case where response is a JSON string
            if (is_string($response)) {
                try {
                    $response = json_decode($response, true);
                    if (!is_array($response)) {
                        $response = [];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to parse response JSON string', [
                        'entry_id' => $entry->id,
                        'error' => $e->getMessage(),
                    ]);
                    $response = [];
                }
            }

            // Ensure response is an array
            if (!is_array($response)) {
                $response = [];
            }

            // Extract and normalize flight data
            $normalizedFlightData = $this->normalizeFlightData($response);

            // Validate that we have sufficient flight data
            if (!$this->hasSufficientFlightData($normalizedFlightData)) {
                return back()->with('error', 'Insufficient flight data to generate ticket. Please ensure flight information (origin, destination, departure date) is available.');
            }

            // Extract passenger data
            $passengerData = $this->extractPassengerData($response, $entry);

            // Log passenger data extraction for debugging
            Log::info('Passenger data extraction result', [
                'entry_id' => $entry->id,
                'passenger_count' => count($passengerData['passengers'] ?? []),
                'passengers' => $passengerData['passengers'] ?? [],
                'has_email' => !empty($passengerData['email']),
                'has_phone' => !empty($passengerData['phone']),
                'response_keys' => array_keys($response),
            ]);

            // Generate the full command block
            $result = $service->generateCommandBlock($normalizedFlightData, $passengerData);
            
            // Log the result to see if passenger line was generated
            Log::info('Command block generation result', [
                'entry_id' => $entry->id,
                'has_passenger_line' => !empty($result['passenger_line']),
                'passenger_line' => $result['passenger_line'] ?? 'EMPTY',
                'full_command_block' => $result['full_command_block'],
            ]);

            // Store the generated command block
            $entry->update([
                'amadeus_command_block' => $result['full_command_block'],
                'amadeus_generated_at' => now(),
            ]);

            Log::info('Amadeus dummy ticket command block generated for entry', [
                'entry_id' => $entry->id,
                'command_block_length' => strlen($result['full_command_block']),
            ]);

            return back()->with('success', 'Amadeus dummy ticket command block generated successfully.');
        } catch (\Throwable $e) {
            Log::error('Failed to generate Amadeus dummy ticket command block for entry', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to generate Amadeus dummy ticket command block: ' . $e->getMessage());
        }
    }

    /**
     * Generate PNR for a submission
     */
    public function generatePnr(
        FfSubmission $submission,
        PnrGenerationService $pnrService,
        DummyTicketPdfService $pdfService
    ) {
        // Abort if submission doesn't exist (route model binding failed)
        if (!$submission->exists) {
            Log::error('PNR generation failed: Submission not found', [
                'submission_id' => $submission->id,
            ]);
            abort(404, 'Submission not found');
        }

        try {
            // Check if PNR already exists
            if ($submission->pnr) {
                return back()->with('error', 'PNR already exists for this submission. PNR: ' . $submission->pnr);
            }

            // Generate PNR (now returns complete extracted data with used_offer)
            $pnrResult = $pnrService->generatePnr($submission);

            // Get extracted data from service result
            $extractedData = $pnrResult['extracted_data'] ?? [];
            $passengers = $extractedData['passengers'] ?? []; // Already normalized format
            
            // Get the flight offer that was successfully used
            $usedOffer = $pnrResult['used_offer'] ?? [];
            
            if (empty($usedOffer)) {
                throw new \RuntimeException('No flight offer available for PDF generation');
            }

            // Get website info
            $website = $submission->website;
            $websiteInfo = $website ? [
                'id' => $website->id,
                'name' => $website->name,
                'slug' => $website->slug,
            ] : null;

            // Generate ONE PDF PER PASSENGER
            $pdfUrls = [];
            $pdfPaths = [];
            
            Log::info('Generating PDFs for passengers', [
                'submission_id' => $submission->id,
                'pnr' => $pnrResult['pnr'],
                'passenger_count' => count($passengers),
            ]);

            foreach ($passengers as $passenger) {
                try {
                    // Generate PDF for this passenger
                    $pdfPath = $pdfService->generate(
                        $pnrResult['pnr'],
                        $usedOffer,
                        $passenger,
                        $websiteInfo
                    );

                    // Verify PDF file was created
                    $fullPdfPath = storage_path('app/public/' . $pdfPath);
                    if (!file_exists($fullPdfPath)) {
                        Log::error('PDF file was not created', [
                            'submission_id' => $submission->id,
                            'passenger' => $passenger['first_name'] . ' ' . $passenger['last_name'],
                            'expected_path' => $fullPdfPath,
                            'pdf_path' => $pdfPath,
                        ]);
                        continue; // Skip this passenger but continue with others
                    }

                    $pdfPaths[] = $pdfPath;
                    $pdfUrls[] = [
                        'passenger_name' => trim(($passenger['last_name'] ?? '') . ' ' . ($passenger['first_name'] ?? '')),
                        'url' => asset('storage/' . $pdfPath),
                    ];

                    Log::info('PDF generated for passenger', [
                        'submission_id' => $submission->id,
                        'passenger' => $passenger['first_name'] . ' ' . $passenger['last_name'],
                        'pdf_path' => $pdfPath,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to generate PDF for passenger', [
                        'submission_id' => $submission->id,
                        'passenger' => $passenger['first_name'] . ' ' . $passenger['last_name'],
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with other passengers
                }
            }

            if (empty($pdfPaths)) {
                throw new \RuntimeException('Failed to generate any PDFs for passengers');
            }

            // Update submission with first PDF path (for backward compatibility)
            $submission->update([
                'pnr' => $pnrResult['pnr'],
                'pnr_generated_at' => now(),
                'pnr_pdf_path' => $pdfPaths[0], // Store first PDF path
                'pnr_source' => $pnrResult['source'],
            ]);

            Log::info('PNR generated successfully with PDFs', [
                'submission_id' => $submission->id,
                'pnr' => $pnrResult['pnr'],
                'source' => $pnrResult['source'],
                'pdf_count' => count($pdfPaths),
                'pdf_urls' => $pdfUrls,
            ]);

            // Return with success message and PDF URLs for frontend
            return back()->with([
                'success' => 'PNR generated successfully: ' . $pnrResult['pnr'] . '. Generated ' . count($pdfPaths) . ' PDF(s).',
                'pnr' => $pnrResult['pnr'],
                'pdfs' => $pdfUrls, // Array of {passenger_name, url}
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to generate PNR', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to generate PNR: ' . $e->getMessage());
        }
    }

    /**
     * Download PNR PDF
     */
    public function downloadPnrPdf(
        FfSubmission $entry,
        PnrGenerationService $pnrService,
        DummyTicketPdfService $pdfService
    ) {
        $submission = $entry; // Alias for consistency with other code
        
        // Always regenerate PDF to ensure latest template is used
        // If PNR exists but PDF path is missing, try to regenerate PDF
        if ($submission->pnr) {
            Log::info('PNR exists but PDF path is missing, attempting to regenerate PDF', [
                'submission_id' => $submission->id,
                'pnr' => $submission->pnr,
                'has_payload' => !empty($submission->payload),
            ]);

            try {
                // Extract data from submission payload (same logic as generatePnr)
                $payload = $submission->payload ?? [];
                $response = $payload['response'] ?? [];
                
                // Handle case where response is a JSON string
                if (is_string($response)) {
                    try {
                        $response = json_decode($response, true);
                        if (!is_array($response)) {
                            $response = [];
                        }
                    } catch (\Exception $e) {
                        $response = [];
                    }
                }
                
                // Extract passengers using the controller's method
                $passengerData = $this->extractPassengerData($response, $submission);
                $passengersForPdf = [];
                
                // Convert passenger data to format expected by PDF service
                foreach ($passengerData['passengers'] ?? [] as $passenger) {
                    $passengersForPdf[] = [
                        'firstName' => strtoupper($passenger['first_name'] ?? 'PASSENGER'),
                        'lastName' => strtoupper($passenger['last_name'] ?? 'TEST'),
                        'dateOfBirth' => $passenger['date_of_birth'] ?? '1990-01-01',
                    ];
                }
                
                // If no passengers found, create a default one
                if (empty($passengersForPdf)) {
                    Log::warning('No passengers found in submission, using default passenger', [
                        'submission_id' => $submission->id,
                        'passenger_data_keys' => array_keys($passengerData),
                    ]);
                    $passengersForPdf[] = [
                        'firstName' => 'PASSENGER',
                        'lastName' => 'TEST',
                        'dateOfBirth' => '1990-01-01',
                    ];
                }
                
                // Extract route and dates from response
                $route = ['origin' => 'UNKNOWN', 'destination' => 'UNKNOWN'];
                $dates = ['departure' => null, 'return' => null];
                
                // Try to find origin and destination
                foreach ($response as $key => $value) {
                    $keyLower = strtolower($key);
                    if (strpos($keyLower, 'flight_from') !== false || 
                        (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'from') !== false && strpos($keyLower, 'json') === false)) {
                        $route['origin'] = is_string($value) ? trim($value) : 'UNKNOWN';
                    }
                    if (strpos($keyLower, 'flight_to') !== false || 
                        (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'to') !== false && strpos($keyLower, 'from') === false && strpos($keyLower, 'json') === false)) {
                        $route['destination'] = is_string($value) ? trim($value) : 'UNKNOWN';
                    }
                    if (strpos($keyLower, 'departure') !== false && strpos($keyLower, 'date') !== false) {
                        $dates['departure'] = is_string($value) ? trim($value) : null;
                    }
                    if (strpos($keyLower, 'return') !== false && strpos($keyLower, 'date') !== false) {
                        $dates['return'] = is_string($value) ? trim($value) : null;
                    }
                }

                // Extract flight_json_data if available for airline/flight number
                $flightData = null;
                foreach ($response as $key => $value) {
                    $keyLower = strtolower($key);
                    if (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'json') !== false) {
                        if (is_string($value)) {
                            try {
                                $flightData = json_decode($value, true);
                            } catch (\Exception $e) {
                                // Ignore parsing errors
                            }
                        } elseif (is_array($value)) {
                            $flightData = $value;
                        }
                        break;
                    }
                }
                
                Log::info('Extracted data for PDF regeneration', [
                    'submission_id' => $submission->id,
                    'passengers_count' => count($passengersForPdf),
                    'route' => $route,
                    'dates' => $dates,
                    'has_flight_data' => !empty($flightData),
                ]);

                // Delete old PDF file if it exists to ensure fresh generation
                if ($submission->pnr_pdf_path) {
                    $oldPdfPath = storage_path('app/public/' . $submission->pnr_pdf_path);
                    if (file_exists($oldPdfPath)) {
                        @unlink($oldPdfPath);
                        Log::info('Deleted old PDF file before regeneration', [
                            'old_path' => $oldPdfPath,
                        ]);
                    }
                }

                // Generate PDF with all available data
                // VERIFICATION: This uses DummyTicketPdfService which renders pdf.dummy-ticket template
                Log::info('Generating PDF using DummyTicketPdfService', [
                    'submission_id' => $submission->id,
                    'service' => 'DummyTicketPdfService',
                    'template' => 'pdf.dummy-ticket',
                    'template_path' => resource_path('views/pdf/dummy-ticket.blade.php'),
                ]);
                
                $pdfPath = $pdfService->generatePdf([
                    'passengers' => $passengersForPdf,
                    'route' => $route,
                    'dates' => $dates,
                    'pnr' => $submission->pnr,
                    'flightData' => $flightData ?? $response, // Pass flight data for airline/flight extraction
                ]);

                // Verify PDF was created
                $fullPdfPath = storage_path('app/public/' . $pdfPath);
                
                if (!file_exists($fullPdfPath)) {
                    throw new \RuntimeException('PDF file was not created at: ' . $fullPdfPath);
                }

                // Update submission with PDF path
                $submission->update(['pnr_pdf_path' => $pdfPath]);
                
                Log::info('PDF regenerated successfully', [
                    'submission_id' => $submission->id,
                    'pdf_path' => $pdfPath,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to regenerate PDF', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return back()->with('error', 'Failed to generate PDF: ' . $e->getMessage());
            }
        }

        // Refresh submission to get latest data
        $submission->refresh();
        
        if (!$submission->pnr_pdf_path) {
            Log::warning('PDF download attempted but pnr_pdf_path is empty', [
                'submission_id' => $submission->id,
                'pnr' => $submission->pnr,
            ]);
            return back()->with('error', 'PDF not found for this submission.');
        }

        $filePath = storage_path('app/public/' . $submission->pnr_pdf_path);

        if (!file_exists($filePath)) {
            Log::error('PDF file not found at expected path', [
                'submission_id' => $submission->id,
                'pnr' => $submission->pnr,
                'expected_path' => $filePath,
                'stored_path' => $submission->pnr_pdf_path,
                'directory_exists' => is_dir(dirname($filePath)),
                'directory_contents' => is_dir(dirname($filePath)) ? scandir(dirname($filePath)) : null,
            ]);
            return back()->with('error', 'PDF file not found at: ' . $submission->pnr_pdf_path);
        }

        // Generate download filename: Itinerary-{passengerName} - {pnr}.pdf
        $downloadFilename = 'Itinerary-Passenger - ' . strtoupper($submission->pnr) . '.pdf';
        
        // Try to extract passenger name from submission payload
        try {
            $payload = $submission->payload ?? [];
            $response = $payload['response'] ?? [];
            
            if (is_string($response)) {
                $response = json_decode($response, true) ?? [];
            }
            
            $passengerData = $this->extractPassengerData($response, $submission);
            if (!empty($passengerData['passengers']) && isset($passengerData['passengers'][0])) {
                $firstPassenger = $passengerData['passengers'][0];
                $firstName = $firstPassenger['first_name'] ?? '';
                $lastName = $firstPassenger['last_name'] ?? '';
                if (!empty($firstName) || !empty($lastName)) {
                    $passengerName = trim(($lastName ?: '') . ' ' . ($firstName ?: ''));
                    if (!empty($passengerName)) {
                        // Sanitize passenger name for filesystem
                        $passengerName = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $passengerName);
                        $passengerName = preg_replace('/\s+/', '-', trim($passengerName));
                        $passengerName = strtoupper($passengerName);
                        $downloadFilename = 'Itinerary-' . $passengerName . ' - ' . strtoupper($submission->pnr) . '.pdf';
                    }
                }
            }
        } catch (\Exception $e) {
            // If extraction fails, use default filename
            Log::warning('Failed to extract passenger name for PDF filename', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return response()->download($filePath, $downloadFilename);
    }









    /**
     * Extract passenger data from response
     * Returns array of passengers, each with first_name, last_name, title
     */
    private function extractPassengerData(array $response, FfSubmission $entry): array
    {
        $passengers = [];

        // Helper function to extract string value from potentially array/object values
        $extractStringValue = function ($value) {
            if (is_string($value)) {
                return trim($value);
            }
            if (is_array($value) && !empty($value)) {
                // Take first element if array
                $firstValue = reset($value);
                return is_string($firstValue) ? trim($firstValue) : null;
            }
            return null;
        };

        // Helper function to parse a name string into first/last
        $parseName = function ($nameString) {
            if (empty($nameString)) {
                return ['first' => null, 'last' => null];
            }
            
            $nameParts = preg_split('/[\s,]+/', trim($nameString), 2);
            if (count($nameParts) >= 2) {
                return ['first' => trim($nameParts[0]), 'last' => trim($nameParts[1])];
            } elseif (count($nameParts) === 1 && !empty($nameParts[0])) {
                // Single name - use as last name
                return ['first' => null, 'last' => trim($nameParts[0])];
            }
            return ['first' => null, 'last' => null];
        };

        $email = null;
        $phone = null;
        $defaultTitle = 'MR';

        // Extract contact info (shared across all passengers)
        foreach ($response as $key => $value) {
            $keyLower = strtolower($key);
            
            // Email
            if (empty($email)) {
                if (strpos($keyLower, 'email') !== false) {
                    $extracted = $extractStringValue($value);
                    if (!empty($extracted) && filter_var($extracted, FILTER_VALIDATE_EMAIL)) {
                        $email = $extracted;
                    }
                } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = trim($value);
                }
            }

            // Phone
            if (empty($phone) && 
                (strpos($keyLower, 'phone') !== false || 
                 strpos($keyLower, 'telephone') !== false ||
                 strpos($keyLower, 'mobile') !== false ||
                 strpos($keyLower, 'cell') !== false)) {
                $extracted = $extractStringValue($value);
                if (!empty($extracted)) {
                    $phone = $extracted;
                }
            }
        }

        // Fallback to entry email if not found in response
        if (empty($email) && !empty($entry->email)) {
            $email = $entry->email;
        }

        // Extract passengers by SCHEMA-BASED detection
        // Iterate over ALL response fields and detect passenger objects
        // Passenger object schema: array/object with first_name and last_name keys
        foreach ($response as $key => $value) {
            // Skip non-array/non-object values
            if (!is_array($value)) {
                continue;
            }
            
            // Check if this value matches passenger schema (has first_name and last_name)
            $hasFirstName = isset($value['first_name']) || isset($value['firstname']);
            $hasLastName = isset($value['last_name']) || isset($value['lastname']);
            
            if ($hasFirstName || $hasLastName) {
                // This is a passenger object - extract data
                $firstName = null;
                $lastName = null;
                $title = $defaultTitle;
                
                // Extract first_name (check multiple possible keys)
                if (isset($value['first_name'])) {
                    $firstName = is_string($value['first_name']) ? trim($value['first_name']) : null;
                } elseif (isset($value['firstname'])) {
                    $firstName = is_string($value['firstname']) ? trim($value['firstname']) : null;
                }
                
                // Extract last_name (check multiple possible keys)
                if (isset($value['last_name'])) {
                    $lastName = is_string($value['last_name']) ? trim($value['last_name']) : null;
                } elseif (isset($value['lastname'])) {
                    $lastName = is_string($value['lastname']) ? trim($value['lastname']) : null;
                }
                
                // Extract title if present
                if (isset($value['title']) || isset($value['salutation'])) {
                    $titleValue = $value['title'] ?? $value['salutation'] ?? null;
                    if (is_string($titleValue) && !empty(trim($titleValue))) {
                        $titleUpper = strtoupper(trim($titleValue));
                        if (in_array($titleUpper, ['MR', 'MS', 'MRS', 'CHD'])) {
                            $title = $titleUpper;
                        }
                    }
                }
                
                // Only add passenger if we have at least first_name or last_name
                if (!empty($firstName) || !empty($lastName)) {
                    // Normalize: trim and uppercase names
                    $firstNameNormalized = !empty($firstName) ? strtoupper(trim($firstName)) : null;
                    $lastNameNormalized = !empty($lastName) ? strtoupper(trim($lastName)) : null;
                    
                    $passengers[] = [
                        'first_name' => $firstNameNormalized,
                        'last_name' => $lastNameNormalized,
                        'title' => $title,
                    ];
                }
            }
        }

        // Filter out passengers without both first and last name
        $passengers = array_filter($passengers, function ($passenger) {
            return !empty($passenger['first_name']) && !empty($passenger['last_name']);
        });

        // Re-index array
        $passengers = array_values($passengers);

        // Return structure with passengers array and contact info
        return [
            'passengers' => $passengers,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * Normalize flight data from various sources into a unified structure
     */
    private function normalizeFlightData(array $response): array
    {
        $normalized = [
            'origin' => null,
            'destination' => null,
            'departure_date' => null,
            'return_date' => null,
            'airline_code' => null,
            'flight_number' => null,
            'cabin' => null,
            'passengers' => null,
            'price' => null,
        ];

        // Priority 1: Check for flight_json_data (Amadeus/Aviationstack format)
        $flightJsonData = null;
        foreach ($response as $key => $value) {
            $keyLower = strtolower($key);
            if (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'json') !== false) {
                $flightJsonData = $value;
                break;
            }
        }

        if ($flightJsonData) {
            // Handle JSON string
            if (is_string($flightJsonData)) {
                try {
                    $flightJsonData = json_decode($flightJsonData, true);
                } catch (\Exception $e) {
                    // If parsing fails, continue with fallback
                }
            }

            if (is_array($flightJsonData)) {
                // Extract from Amadeus/Aviationstack JSON structure
                $itineraries = $flightJsonData['itineraries'] ?? [];
                if (!empty($itineraries)) {
                    $firstItinerary = $itineraries[0];
                    $segments = $firstItinerary['segments'] ?? [];
                    if (!empty($segments)) {
                        $firstSegment = $segments[0];
                        $normalized['origin'] = $firstSegment['departure']['iataCode'] ?? null;
                        $normalized['carrier_code'] = $firstSegment['carrierCode'] ?? null;
                        $normalized['flight_number'] = $firstSegment['number'] ?? null;

                        $lastSegment = $segments[count($segments) - 1];
                        $normalized['destination'] = $lastSegment['arrival']['iataCode'] ?? null;

                        if (!empty($firstSegment['departure']['at'])) {
                            $normalized['departure_date'] = $firstSegment['departure']['at'];
                        }
                    }

                    // Check for return flight
                    if (count($itineraries) > 1) {
                        $returnItinerary = $itineraries[1];
                        $returnSegments = $returnItinerary['segments'] ?? [];
                        if (!empty($returnSegments)) {
                            $firstReturnSegment = $returnSegments[0];
                            if (!empty($firstReturnSegment['departure']['at'])) {
                                $normalized['return_date'] = $firstReturnSegment['departure']['at'];
                            }
                        }
                    }
                }

                // Extract validatingAirlineCodes (priority source for airline code)
                $validatingAirlineCodes = $flightJsonData['validatingAirlineCodes'] ?? [];
                if (!empty($validatingAirlineCodes) && is_array($validatingAirlineCodes)) {
                    $normalized['validating_airline_codes'] = $validatingAirlineCodes;
                }

                // Extract price
                $price = $flightJsonData['price'] ?? null;
                if ($price && is_array($price)) {
                    $normalized['price'] = $price['total'] ?? $price['grandTotal'] ?? null;
                }

                // Extract passenger count
                $travelerPricings = $flightJsonData['travelerPricings'] ?? [];
                if (!empty($travelerPricings)) {
                    $normalized['passengers'] = count($travelerPricings);
                }

                // Extract cabin class
                if (!empty($travelerPricings[0]['fareDetailsBySegment'][0]['cabin'])) {
                    $normalized['cabin'] = $travelerPricings[0]['fareDetailsBySegment'][0]['cabin'];
                }
            }
        }

        // Priority 2: Fallback to basic form fields
        if (empty($normalized['origin'])) {
            foreach ($response as $key => $value) {
                $keyLower = strtolower($key);
                
                if (strpos($keyLower, 'flight_from') !== false || 
                    (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'from') !== false)) {
                    $normalized['origin'] = is_string($value) ? trim($value) : null;
                }
                
                if (strpos($keyLower, 'flight_to') !== false || 
                    (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'to') !== false && strpos($keyLower, 'from') === false)) {
                    $normalized['destination'] = is_string($value) ? trim($value) : null;
                }
                
                if (strpos($keyLower, 'flight_departure') !== false || 
                    (strpos($keyLower, 'departure') !== false && strpos($keyLower, 'date') !== false)) {
                    $normalized['departure_date'] = is_string($value) ? trim($value) : null;
                }
                
                if (strpos($keyLower, 'flight_arrival') !== false || 
                    (strpos($keyLower, 'arrival') !== false && strpos($keyLower, 'date') !== false)) {
                    $normalized['return_date'] = is_string($value) ? trim($value) : null;
                }
            }
        }

        return array_filter($normalized, fn($value) => $value !== null && $value !== '');
    }

    /**
     * Check if we have sufficient flight data to generate a code
     */
    private function hasSufficientFlightData(array $normalizedData): bool
    {
        // For structured code format, we need: origin, destination, and departure_date
        return !empty($normalizedData['origin']) && 
               !empty($normalizedData['destination']) && 
               !empty($normalizedData['departure_date']);
    }
}

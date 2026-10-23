<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionItinerary extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'generated_at' => 'immutable_datetime'];
    }

    public function submission()
    {
        return $this->belongsTo(FfSubmission::class, 'ff_submission_id');
    }

    public function document(): array
    {
        return array_merge($this->snapshot ?? [], [
            'reference' => $this->reference,
            'reference_type' => $this->reference_type,
            'external_pnr' => $this->external_pnr,
            'booking_source' => $this->booking_source,
            'generated_at' => $this->generated_at->toIso8601String(),
        ]);
    }

    public function filename(): string
    {
        $external = $this->reference_type === 'external' && filled($this->external_pnr);
        $reference = $external ? $this->external_pnr : $this->reference;
        $reference = preg_replace('/[^A-Za-z0-9-]/', '-', (string) $reference);

        return ($external ? 'Booked-itinerary-PNR-' : 'Itinerary-').$reference.'.pdf';
    }

    public function summary(bool $canUpdate): array
    {
        return [
            'reference' => $this->reference,
            'reference_type' => $this->reference_type,
            'external_pnr' => $this->external_pnr,
            'booking_source' => $this->booking_source,
            'generated_at' => $this->generated_at->toIso8601String(),
            'download_url' => route('submissions.itinerary.download', $this->ff_submission_id, false),
            'can_update' => $canUpdate,
        ];
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use App\Models\FfSubmission;

class FfSubmissionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view the submissions list
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FfSubmission $submission): bool
    {
        // User can view if they own the website or are an admin
        return $submission->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Submissions are created via webhooks, not by users
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FfSubmission $submission): bool
    {
        // User can update if they own the website or are an admin
        return $submission->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FfSubmission $submission): bool
    {
        // User can delete if they own the website or are an admin
        return $submission->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can generate Amadeus code for the submission.
     */
    public function generateAmadeusCode(User $user, FfSubmission $submission): bool
    {
        // Same authorization as update
        return $this->update($user, $submission);
    }

    /**
     * Determine whether the user can generate PNR for the submission.
     */
    public function generatePnr(User $user, FfSubmission $submission): bool
    {
        // Same authorization as update
        return $this->update($user, $submission);
    }

    /**
     * Determine whether the user can download PDF for the submission.
     */
    public function downloadPdf(User $user, FfSubmission $submission): bool
    {
        // Same authorization as view
        return $this->view($user, $submission);
    }
}

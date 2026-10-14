<?php

namespace App\Actions;

use App\Models\Posting;

/**
 * Soft-deletes a posting so API sync clients learn about it. The counter
 * entry FK's null-on-delete only fires for hard deletes, so links pointing
 * at the posting are cleared here — through Eloquent, so the linked
 * postings' `updated_at` moves too.
 */
final class DeletePosting
{
    public function handle(Posting $posting): void
    {
        $posting->delete();

        Posting::query()
            ->where('counter_entry_posting_id', $posting->id)
            ->update(['counter_entry_posting_id' => null]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListPostingsRequest;
use App\Http\Resources\PostingResource;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Queries\PostingFilters;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Postings', weight: 3)]
class PostingController extends Controller
{
    /**
     * List postings
     *
     * Cursor-paginated: follow `meta.next_cursor` (or `links.next`) until it
     * is null to iterate every matching posting. Ordering is by booking
     * `date` with `id` as tie-breaker, and pages are read live rather than
     * from a snapshot: postings added or removed mid-iteration never shift
     * other postings into a gap or a duplicate, but a posting whose booking
     * date is edited mid-iteration can be seen twice or missed.
     *
     * For incremental sync, pass the time the previous sync started, minus a
     * safety margin, as `filter[changed_since]`; upsert results by `id` and
     * remove those with a non-null `deleted_at`. That also picks up postings
     * edited during the previous iteration. The margin covers changes
     * committed after their `updated_at` was stamped (e.g. a long-running
     * import); an hour is ample. Postings of a deleted bank account are
     * removed with it and not reported: drop them when the account
     * disappears from the bank accounts list. `meta.totals` excludes
     * deleted postings.
     */
    public function index(ListPostingsRequest $request): AnonymousResourceCollection
    {
        return $this->list($request, Posting::query()->forUser($request->user()));
    }

    /**
     * List postings for a bank account
     *
     * Same filters, sorting and pagination as listing all postings, scoped
     * to one account.
     */
    public function accountIndex(ListPostingsRequest $request, BankAccount $bankAccount): AnonymousResourceCollection
    {
        abort_unless($bankAccount->user_id === $request->user()->id, 404);

        return $this->list($request, $bankAccount->postings()->getQuery()->forUser($request->user()));
    }

    /**
     * Show posting
     */
    public function show(Request $request, Posting $posting): PostingResource
    {
        $this->authorizeRead($request);

        abort_unless($posting->user_id === $request->user()->id, 404);

        return new PostingResource($posting->load(['bankAccount', 'categorizedSubcategory.category']));
    }

    /**
     * @param  Builder<Posting>  $baseQuery
     */
    private function list(ListPostingsRequest $request, $baseQuery): AnonymousResourceCollection
    {
        // Incremental sync must learn about deletions, so trashed postings
        // are only ever listed under `changed_since`.
        if ($request->has('filter.changed_since')) {
            $baseQuery->withTrashed();
        }

        $query = PostingFilters::apply($baseQuery, $request);

        // Cursor pagination needs a unique ordering. The default sort already
        // ends in `-id`; an explicit `sort` breaks `date` ties on id here.
        if ($request->has('sort')) {
            $query->orderBy('id', $request->query('sort') === 'date' ? 'asc' : 'desc');
        }

        $with = array_values(array_intersect_key(
            ['bank_account' => 'bankAccount', 'category' => 'categorizedSubcategory.category'],
            array_flip($request->includes()),
        ));

        $totals = null;
        if ($request->boolean('with_totals')) {
            $aggregate = $query->clone()->getEloquentBuilder()->toBase()->reorder()->whereNull((new Posting)->getQualifiedDeletedAtColumn())
                ->selectRaw('count(*) as count, coalesce(sum(amount), 0) as amount_sum')
                ->first();
            $totals = ['count' => (int) $aggregate->count, 'amount_sum' => (float) $aggregate->amount_sum];
        }

        $collection = PostingResource::collection(
            $query->with($with)->cursorPaginate($request->integer('per_page', 50))->withQueryString(),
        );

        return $totals === null ? $collection : $collection->additional(['meta' => ['totals' => $totals]]);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('read'), 403);
    }
}

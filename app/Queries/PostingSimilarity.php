<?php

namespace App\Queries;

use App\Models\Posting;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds similar postings via pgvector cosine distance over description
 * embeddings, and derives category suggestions from the closest
 * already-categorized neighbors.
 */
final class PostingSimilarity
{
    /**
     * How many nearest neighbors feed a category suggestion.
     */
    public const int NEIGHBOR_LIMIT = 10;

    /**
     * Minimum similarity (0-1) for a neighbor to count as evidence for a
     * suggestion.
     */
    public const float SUGGEST_MIN_SIMILARITY = 0.75;

    /**
     * Minimum top-neighbor similarity for a suggestion to be worth
     * surfacing at all.
     */
    public const float SUGGEST_TOP_SIMILARITY = 0.8;

    /**
     * Stricter bars for the opt-in auto-categorize: the closest match must
     * be near-identical and its subcategory must dominate the neighbors.
     */
    public const float AUTO_MIN_SIMILARITY = 0.92;

    public const float AUTO_MIN_VOTE_SHARE = 0.6;

    public function __construct(private PlatformSettings $settings) {}

    /**
     * Whether similarity queries can run: the feature is enabled and keyed,
     * and the database driver supports vector distance (Postgres/MariaDB —
     * sqlite, e.g. tests, does not).
     */
    public function supported(): bool
    {
        return $this->settings->similarityAvailable()
            && Posting::query()->getQuery()->getGrammar()->supportsVectorDistance();
    }

    /**
     * The nearest postings to the given one, most similar first. Each
     * result carries a `similarity` attribute between 0 and 1.
     *
     * @return Collection<int, Posting>
     */
    public function similarTo(Posting $posting, int $limit = 8, int $offset = 0): Collection
    {
        if (! $this->supported() || $posting->description_embedding === null) {
            return new Collection;
        }

        return Posting::query()
            ->forUser($posting->user)
            ->whereKeyNot($posting->id)
            ->whereNotNull('description_embedding')
            ->select('postings.*')
            ->selectVectorDistance('description_embedding', $posting->description_embedding, 'distance')
            ->orderByVectorDistance('description_embedding', $posting->description_embedding)
            ->orderByDesc('postings.date')
            ->orderBy('postings.id')
            ->offset($offset)
            ->limit($limit)
            ->with(['bankAccount', 'categorizedSubcategory.category', 'subcategory'])
            ->get()
            ->each(function (Posting $match): void {
                $match->similarity = max(0.0, round(1 - (float) $match->distance, 4));
            });
    }

    /**
     * Derive a suggested subcategory for an uncategorized posting from its
     * nearest categorized neighbors: similarity-weighted vote, requiring a
     * sufficiently close top match. Returns null when there is no confident
     * suggestion.
     *
     * @return array{subcategory_id: int, top_similarity: float, vote_share: float}|null
     */
    public function suggestionFor(Posting $posting): ?array
    {
        $neighbors = $this->similarTo($posting, self::NEIGHBOR_LIMIT)
            ->filter(
                fn (Posting $match): bool => $match->categorized_subcategory_id !== null
                    && $match->similarity >= self::SUGGEST_MIN_SIMILARITY,
            );

        $topSimilarity = $neighbors->max('similarity');

        if ($neighbors->isEmpty() || $topSimilarity < self::SUGGEST_TOP_SIMILARITY) {
            return null;
        }

        /** @var array<int, float> $votes */
        $votes = [];

        foreach ($neighbors as $match) {
            $votes[$match->categorized_subcategory_id] = ($votes[$match->categorized_subcategory_id] ?? 0) + $match->similarity;
        }

        arsort($votes);

        $winner = (int) array_key_first($votes);

        return [
            'subcategory_id' => $winner,
            'top_similarity' => $topSimilarity,
            'vote_share' => round($votes[$winner] / array_sum($votes), 4),
        ];
    }

    /**
     * Whether a suggestion clears the auto-categorize bars.
     *
     * @param  array{subcategory_id: int, top_similarity: float, vote_share: float}|null  $suggestion
     */
    public function autoApplicable(?array $suggestion): bool
    {
        return $suggestion !== null
            && $suggestion['top_similarity'] >= self::AUTO_MIN_SIMILARITY
            && $suggestion['vote_share'] >= self::AUTO_MIN_VOTE_SHARE;
    }

    /**
     * Backfill the `subcategory_id` suggestion slot for uncategorized
     * postings that have an embedding but no suggestion and no imported
     * hint. Returns how many suggestions were written.
     */
    public function fillSuggestions(int $limit = 500): int
    {
        if (! $this->supported()) {
            return 0;
        }

        $candidates = Posting::query()
            ->whereNull('categorized_subcategory_id')
            ->whereNull('subcategory_id')
            ->whereNotNull('description_embedding')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $filled = 0;

        foreach ($candidates as $posting) {
            $suggestion = $this->suggestionFor($posting);

            if ($suggestion === null) {
                continue;
            }

            $posting->update(['subcategory_id' => $suggestion['subcategory_id']]);
            $filled++;
        }

        return $filled;
    }

    /**
     * Opt-in sweep: confirm high-confidence suggestions as real
     * categorizations, tagged `categorized_by = 'similarity'`.
     */
    public function applyAutoCategorization(int $limit = 500): int
    {
        if (! $this->supported() || ! $this->settings->similarityAutoCategorize()) {
            return 0;
        }

        $candidates = Posting::query()
            ->whereNull('categorized_subcategory_id')
            ->whereNotNull('description_embedding')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $applied = 0;

        foreach ($candidates as $posting) {
            $suggestion = $this->suggestionFor($posting);

            if (! $this->autoApplicable($suggestion)) {
                continue;
            }

            $posting->update([
                'categorized_subcategory_id' => $suggestion['subcategory_id'],
                'categorized_by' => 'similarity',
            ]);
            $applied++;
        }

        return $applied;
    }
}

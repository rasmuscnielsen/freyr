<?php

namespace App\Rules;

use App\Services\Embeddings\EmbeddingGenerator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes only when OpenAI accepts the key for an embedding request, so a
 * key that cannot work is never saved.
 */
class WorkingOpenAiKey implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = app(EmbeddingGenerator::class)->openAiKeyProblem(trim((string) $value));

        if ($problem !== null) {
            $fail($problem);
        }
    }
}

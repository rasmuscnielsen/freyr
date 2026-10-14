<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'custom_date' => $this->custom_date?->toDateString(),
            'description' => $this->description,
            'original_description' => $this->original_description,
            'comment' => $this->comment,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'original_amount' => $this->original_amount === null ? null : (float) $this->original_amount,
            'balance' => $this->balance === null ? null : (float) $this->balance,
            'currency' => $this->currency,
            'original_currency' => $this->original_currency,
            'is_extraordinary' => $this->is_extraordinary,
            'source' => $this->source,
            'categorized_by' => $this->categorized_by,
            'counter_entry_posting_id' => $this->counter_entry_posting_id,
            'is_account_group_duplicate' => $this->is_account_group_duplicate,
            'external_posting_id' => $this->external_posting_id,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => [
                'id' => $this->bankAccount->id,
                'name' => $this->bankAccount->name,
                'bank_name' => $this->bankAccount->bank_name,
                'currency' => $this->bankAccount->currency,
            ]),
            'category' => $this->whenLoaded('categorizedSubcategory', fn () => $this->categorizedSubcategory?->category === null ? null : [
                'id' => $this->categorizedSubcategory->category->id,
                'name' => $this->categorizedSubcategory->category->name,
                'type' => $this->categorizedSubcategory->category->type,
            ]),
            'subcategory' => $this->whenLoaded('categorizedSubcategory', fn () => $this->categorizedSubcategory === null ? null : [
                'id' => $this->categorizedSubcategory->id,
                'name' => $this->categorizedSubcategory->name,
                'type' => $this->categorizedSubcategory->category?->type,
            ]),
            'suggested_subcategory' => $this->whenLoaded('subcategory', fn () => $this->subcategory === null ? null : [
                'id' => $this->subcategory->id,
                'name' => $this->subcategory->name,
            ]),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            /** Set only on deleted postings, which are listed under `filter[changed_since]`. */
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}

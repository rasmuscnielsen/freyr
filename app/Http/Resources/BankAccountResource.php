<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
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
            'name' => $this->name,
            'bank_key' => $this->bank_key,
            'bank_name' => $this->bank_name,
            'currency' => $this->currency,
            'last_booked_balance' => $this->last_booked_balance === null ? null : (float) $this->last_booked_balance,
            'last_available_balance' => $this->last_available_balance === null ? null : (float) $this->last_available_balance,
            'account_type' => $this->account_type,
            'account_subcategory_key' => $this->account_subcategory_key,
            'last_updated_at' => $this->last_updated_at?->toJSON(),
            'last_known_posting_date' => $this->last_known_posting_date?->toDateString(),
            'is_active' => $this->is_active,
            'is_favorite' => $this->is_favorite,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}

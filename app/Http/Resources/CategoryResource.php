<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'type' => $this->type,
            'hyphenated_name' => $this->hyphenated_name,
            'subcategories' => $this->whenLoaded('subcategories', fn () => $this->subcategories->map(fn ($subcategory): array => [
                'id' => $subcategory->id,
                'name' => $subcategory->name,
                'type' => $this->type,
                'hyphenated_name' => $subcategory->hyphenated_name,
                'expense_type' => $subcategory->expense_type,
            ])->values()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}

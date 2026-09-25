<?php

namespace App\Http\Requests;

use App\Enums\SyncHistory;
use App\Models\BankAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkBankConnectionAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['existing', 'create', 'ignore'])],
            'bank_account_id' => [
                'nullable',
                'required_if:mode,existing',
                Rule::exists('bank_accounts', 'id')
                    ->where('user_id', $this->user()?->id)
                    // Only Spiir-imported accounts may attach to a live feed.
                    ->where(fn (Builder $query) => $query
                        ->where('legacy_id', 'not like', BankAccount::MANUAL_LEGACY_ID_PREFIX.'%')
                        ->where('legacy_id', 'not like', BankAccount::ENABLE_BANKING_LEGACY_ID_PREFIX.'%')),
            ],
            'name' => ['nullable', 'required_if:mode,create', 'string', 'max:255'],
            'history' => ['nullable', Rule::enum(SyncHistory::class)],
        ];
    }
}

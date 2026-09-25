<?php

namespace App\Http\Requests;

use App\Enums\SyncHistory;
use App\Models\BankConnectionAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MapBankConnectionAccountsRequest extends FormRequest
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
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*.id' => [
                'required',
                'integer',
                Rule::exists('bank_connection_accounts', 'id')
                    ->where('bank_connection_id', $this->route('bankConnection')->id),
            ],
            'accounts.*.mode' => ['required', Rule::in(['existing', 'create', 'ignore'])],
            'accounts.*.name' => ['nullable', 'required_if:accounts.*.mode,create', 'string', 'max:255'],
            'accounts.*.bank_account_id' => [
                'nullable',
                'required_if:accounts.*.mode,existing',
                'distinct',
                Rule::exists('bank_accounts', 'id')->where('user_id', $this->user()?->id),
            ],
            'accounts.*.history' => ['nullable', Rule::enum(SyncHistory::class)],
        ];
    }

    /**
     * Reject local accounts that are already linked to another remote account.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('accounts', []) as $index => $item) {
                $bankAccountId = $item['bank_account_id'] ?? null;
                $remoteId = $item['id'] ?? null;

                if (! is_numeric($bankAccountId) || ! is_numeric($remoteId)) {
                    continue;
                }

                $alreadyLinked = BankConnectionAccount::query()
                    ->where('bank_account_id', (int) $bankAccountId)
                    ->where('id', '!=', (int) $remoteId)
                    ->exists();

                if ($alreadyLinked) {
                    $validator->errors()->add(
                        "accounts.{$index}.bank_account_id",
                        'This account is already linked to another bank account.',
                    );
                }
            }
        });
    }
}

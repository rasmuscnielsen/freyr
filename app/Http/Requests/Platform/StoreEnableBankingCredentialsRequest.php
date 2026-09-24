<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreEnableBankingCredentialsRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'string', 'max:255'],
            'private_key' => ['required', 'file', 'max:64'],
        ];
    }

    /**
     * Ensure the uploaded file actually holds a usable PEM private key.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('private_key')) {
                return;
            }

            $pem = $this->privateKeyContents();

            if ($pem === '' || ! Str::contains($pem, '-----BEGIN') || openssl_pkey_get_private($pem) === false) {
                $validator->errors()->add('private_key', 'The file does not contain a valid PEM private key.');
            }
        });
    }

    public function privateKeyContents(): string
    {
        $file = $this->file('private_key');

        if ($file === null) {
            return '';
        }

        return (string) file_get_contents($file->getRealPath());
    }
}

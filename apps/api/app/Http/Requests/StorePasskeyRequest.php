<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePasskeyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'passkey' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}

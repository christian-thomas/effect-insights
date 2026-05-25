<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
        ];
    }

    public function persist(): User
    {
        /** @var User $user */
        $user = $this->user();
        $user->update($this->validated());

        return $user->refresh();
    }
}

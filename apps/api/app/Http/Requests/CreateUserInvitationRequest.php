<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateUserInvitationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:191'],
            'role' => ['required', Rule::in([
                OrganisationRole::Owner->value,
                OrganisationRole::Admin->value,
                OrganisationRole::Viewer->value,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Organisation $organisation */
            $organisation = $this->route('organisation');
            $email = (string) $this->input('email');

            // Reject if the email already belongs to a user that's already
            // a member of this org — the invite would be a no-op.
            $existing = User::query()
                ->where('email', $email)
                ->whereHas('organisations', fn ($query) => $query->whereKey($organisation->getKey()))
                ->exists();

            if ($existing) {
                $validator->errors()->add('email', 'That email is already a member of this organisation.');

                return;
            }

            // Reject if there's a pending invite for the same email + org.
            $pendingInvite = UserInvitation::query()
                ->where('organisation_id', $organisation->getKey())
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pendingInvite) {
                $validator->errors()->add('email', 'A pending invitation already exists for that email.');
            }
        });
    }

    public function persist(Organisation $organisation, User $invitedBy): UserInvitation
    {
        return UserInvitation::create([
            'organisation_id' => $organisation->getKey(),
            'email' => $this->validated('email'),
            'role' => $this->validated('role'),
            'token' => UserInvitation::generateToken(),
            'expires_at' => CarbonImmutable::now()->addDays(UserInvitation::DEFAULT_TTL_DAYS),
            'invited_by_user_id' => $invitedBy->getKey(),
        ]);
    }
}

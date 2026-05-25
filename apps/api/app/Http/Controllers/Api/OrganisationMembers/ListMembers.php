<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OrganisationMembers;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;

class ListMembers extends Controller
{
    public function __invoke(Organisation $organisation): JsonResponse
    {
        $members = $organisation->users()
            ->orderBy('name')
            ->get()
            ->map(fn ($user): array => [
                'id' => $user->getKey(),
                'name' => $user->getAttribute('name'),
                'email' => $user->getAttribute('email'),
                'role' => (string) $user->pivot->getAttribute('role'),
                'joinedAt' => optional($user->pivot->getAttribute('created_at'))?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $members]);
    }
}

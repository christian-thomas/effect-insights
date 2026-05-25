<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\OrganisationMembers;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\JsonResponse;

class ListMembers extends Controller
{
    public function __invoke(Organisation $organisation): JsonResponse
    {
        $members = $organisation->users()
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                /** @var Pivot $pivot */
                $pivot = $user->getRelation('pivot');

                return [
                    'id' => $user->getKey(),
                    'name' => $user->getAttribute('name'),
                    'email' => $user->getAttribute('email'),
                    'role' => (string) $pivot->getAttribute('role'),
                    'joinedAt' => $pivot->getAttribute('created_at')?->toIso8601String(),
                ];
            })
            ->all();

        return response()->json(['data' => $members]);
    }
}

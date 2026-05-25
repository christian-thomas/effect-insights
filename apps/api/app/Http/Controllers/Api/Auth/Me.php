<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Me extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Force 200 — JsonResource::calculateStatus() returns 201 if the
        // model's `wasRecentlyCreated` is still true (which it is when /me
        // is hit in the same test as a user-creating endpoint via the same
        // session). /me is always a read, never a create.
        return UserResource::make($user->load('organisations'))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}

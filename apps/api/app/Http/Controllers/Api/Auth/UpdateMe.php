<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class UpdateMe extends Controller
{
    public function __invoke(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->persist();

        return UserResource::make($user->load('organisations'))->response();
    }
}

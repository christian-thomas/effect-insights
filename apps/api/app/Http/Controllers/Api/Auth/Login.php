<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LogInUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Login extends Controller
{
    public function __invoke(Request $request, LogInUser $login): JsonResponse
    {
        $user = $login($request);

        return UserResource::make($user->load('organisations'))->response();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ChangePassword extends Controller
{
    public function __invoke(ChangePasswordRequest $request): Response
    {
        $request->persist();

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}

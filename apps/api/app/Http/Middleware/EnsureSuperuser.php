<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guards routes that should only be reachable by Effect staff holding the
 * `superuser` global role (spatie/laravel-permission).
 */
class EnsureSuperuser
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->isSuperuser) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'Superuser role required');
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Http\Requests\SwitchOrganisationRequest;
use App\Http\Resources\UserResource;
use App\Models\Organisation;
use App\Models\User;
use App\Support\CurrentOrganisation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SwitchOrganisation extends Controller
{
    public function __invoke(SwitchOrganisationRequest $request): JsonResponse
    {
        $organisationId = $request->string('organisation_id')->toString();

        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperuser) {
            $isMember = $user->organisations()->whereKey($organisationId)->exists();

            if (! $isMember) {
                throw new HttpException(
                    Response::HTTP_FORBIDDEN,
                    'You are not a member of that organisation',
                );
            }
        }

        $organisation = Organisation::query()->whereKey($organisationId)->firstOrFail();

        $request->session()->put('current_organisation_id', $organisationId);
        app(CurrentOrganisation::class)->set($organisation);

        return UserResource::make($user->load('organisations'))->response();
    }
}

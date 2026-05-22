<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Organisations;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use Illuminate\Http\Response;

class DeleteOrganisation extends Controller
{
    public function __invoke(Organisation $organisation): Response
    {
        $organisation->delete();

        return response()->noContent();
    }
}

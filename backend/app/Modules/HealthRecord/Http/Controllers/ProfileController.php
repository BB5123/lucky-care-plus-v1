<?php

namespace App\Modules\HealthRecord\Http\Controllers;

use App\Models\User;
use App\Modules\HealthRecord\Application\ProfileListAssembler;
use App\Modules\HealthRecord\Domain\HealthRecordNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /workspaces/{workspace}/profiles` — the profiles this actor may enter.
 *
 * The route-level `health_profile.read` guard proves membership and the role
 * gate. Which profiles are actually visible is decided per profile by
 * `ProfileListAssembler` → `Authorizer::authorizeBatch`, so a caregiver or
 * viewer with a grant sees exactly the granted profile(s), while an actor with
 * no readable profile gets `{"data": []}` (200), not a 403. The controller
 * never compares roles or grants itself.
 */
final class ProfileController extends HealthVaultController
{
    public function index(Request $request, ProfileListAssembler $assembler): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new HealthRecordNotFoundException;
        }

        return response()->json([
            'data' => $assembler->assemble($this->workspace($request), $actor),
        ]);
    }
}

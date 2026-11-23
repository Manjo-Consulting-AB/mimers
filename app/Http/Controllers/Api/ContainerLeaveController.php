<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\LeaveContainer;
use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/containers/{container}/leave — 204 (issue 1529 · M75). Ett andra
 * anrop ger samma svar utan fel; utan någon rad alls blir det 403.
 */
class ContainerLeaveController extends Controller
{
    public function store(Request $request, Container $container, LeaveContainer $leaveContainer): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $leaveContainer->hasLeft($user, $container)) {
            Gate::authorize('leave', $container);
        }

        $leaveContainer->handle($user, $container);

        return response()->noContent();
    }
}

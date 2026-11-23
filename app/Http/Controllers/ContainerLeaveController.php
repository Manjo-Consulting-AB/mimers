<?php

namespace App\Http\Controllers;

use App\Actions\Access\LeaveContainer;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /containers/{container}/leave — mottagaren lämnar containern (issue
 * 1529 · M75). Grinden är `ContainerPolicy::leave()`; en person utan giltig
 * rad får 403. Den som redan lämnat får samma svar som första gången.
 */
class ContainerLeaveController extends Controller
{
    public function store(Request $request, Container $container, LeaveContainer $leaveContainer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $leaveContainer->hasLeft($user, $container)) {
            Gate::authorize('leave', $container);
        }

        $leaveContainer->handle($user, $container);

        return redirect()->route('dashboard')->with('status', 'container-left');
    }
}

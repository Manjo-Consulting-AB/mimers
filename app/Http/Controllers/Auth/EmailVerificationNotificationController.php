<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Skickar om verifieringsmejlet till den inloggade användaren. Samma
 * kontroller registreras både i routes/web.php (sessionsguard) och
 * routes/api.php (`auth:sanctum`) — se issue 4 § "Båda ytorna delar
 * FormRequests och policies". Ingen indata att validera här, så det är
 * kontrollern själv (inte en FormRequest) som delas mellan ytorna.
 */
class EmailVerificationNotificationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        if ($request->user()->hasVerifiedEmail()) {
            if ($request->wantsJson()) {
                return response()->noContent();
            }

            return redirect()->intended(route('welcome'));
        }

        $request->user()->sendEmailVerificationNotification();

        if ($request->wantsJson()) {
            return response()->noContent(202);
        }

        return back()->with('status', 'verification-link-sent');
    }
}

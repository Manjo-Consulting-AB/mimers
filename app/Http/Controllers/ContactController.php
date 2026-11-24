<?php

namespace App\Http\Controllers;

use App\Actions\Contact\DetectTrappedContact;
use App\Actions\Contact\SendContactMessage;
use App\Http\Requests\ContactRequest;
use App\Support\Api\ApiError;
use App\Support\Contact\ContactCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Kontaktformuläret — `GET /contact` och `POST /contact`, M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 1, 3, 4 och 6.
 *
 * **Sidan ligger utanför `auth` och `guest`**, som informationssidorna: en
 * gäst och en inloggad ska nå den, och en plats i någon av grupperna hade
 * skickat den ena parten vidare. Skillnaden ligger i formuläret och i
 * inskickningen, inte i rutten.
 *
 * **En inloggad persons identitet kommer ur sessionen.** `user->name` och
 * `user->email` skickas med sidan, visas men kan inte ändras, och läses på
 * inskickningen ur `$request->user()` — aldrig ur kroppen. `ContactRequest`
 * bär inga regler för fälten när någon är inloggad, så ett `name` eller
 * `email` i begäran når inte ens `validated()`.
 *
 * **POST svarar JSON.** Valideringsfelen följer API-höljet
 * (AGENTS.md § Felformat i API:et) med en kod per fält, och sidan hämtar
 * svaret med `fetch` och översätter koden — samma form som
 * App\Http\Controllers\HelpFeedbackController. Bekräftelsen är densamma för
 * ett riktigt ärende och för en skräpträff: en bot ska inte kunna skilja sin
 * egen inskickning från en levererad.
 *
 * **Mejlet skickas synkront.** Det finns ingen tabell att falla tillbaka på,
 * så ett undantag från mailern blir ett felsvar i stället för ett tystnat
 * köjobb. Felet loggas med `contact.send_failed` och kategorin, men utan
 * meddelandets text, namnet och adressen.
 */
class ContactController extends Controller
{
    /**
     * Sidan. Kategorierna kommer i ADR-0096:s ordning ur
     * App\Support\Contact\ContactCategory, och `rendered_at` är serverns
     * tidsstämpel krypterad — det är beviset tidsfällan läser på
     * inskickningen.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Contact', [
            'categories' => ContactCategory::keys(),
            'rendered_at' => Crypt::encryptString(now()->toIso8601String()),
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }

    /**
     * Inskickningen. Skräpträffen svarar som ett riktigt ärende och skickar
     * inget mejl; ett mailerfel svarar `503 contact.send_failed`.
     */
    public function store(
        ContactRequest $request,
        DetectTrappedContact $detectTrapped,
        SendContactMessage $send,
    ): JsonResponse {
        $user = $request->user();
        $category = (string) $request->validated('category');

        $reason = $detectTrapped->handle($request);

        if ($reason !== null) {
            Log::info('contact.trapped', [
                'category' => $category,
                'reason' => $reason,
            ]);

            return response()->json(['status' => 'sent']);
        }

        // Den inloggades identitet kommer ur sessionen, gästens ur kroppen.
        if ($user === null) {
            $name = (string) $request->validated('name');
            $email = (string) $request->validated('email');
        } else {
            $name = $user->name;
            $email = $user->email;
        }

        try {
            $send->handle($category, $name, $email, (string) $request->validated('message'), $user);
        } catch (Throwable $exception) {
            Log::error('contact.send_failed', [
                'category' => $category,
                'exception' => $exception,
            ]);

            return ApiError::response('contact.send_failed', [], 503);
        }

        return response()->json(['status' => 'sent']);
    }
}

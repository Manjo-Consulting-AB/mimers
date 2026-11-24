<?php

namespace App\Http\Controllers;

use App\Http\Requests\Help\HelpFeedbackStoreRequest;
use App\Http\Requests\Help\HelpFeedbackUpdateRequest;
use App\Models\HelpFeedback;
use App\Support\Api\ApiError;
use App\Support\Help\HelpLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * *Was this helpful?* på artikelsidan — M82 · issue 471,
 * [[ADR-0094 Hjälpcentret]] § 7.
 *
 * Två rutter, båda utanför `auth` och `guest`: en gäst ska kunna säga Ja
 * eller Nej lika väl som en inloggad. Skillnaden är att bara den inloggade
 * får skriva en kommentar — den regeln ägs av `update()`, inte av rutten.
 *
 * **Raden hittas genom sessionen.** En gäst kan bara ändra den rad hen själv
 * nyss skapade, och beviset är ULID:n som `store()` lägger i sessionen under
 * `help_feedback` (artikel-id → ULID). En inloggad kan dessutom ändra en rad
 * som bär hens `user_id`, även om sessionen är ny. Allt annat svarar `404`:
 * att en rad finns är inget klienten ska kunna räkna ut (ADR-0017).
 *
 * **Orsaken och kommentaren sparas bara på ett Nej.** Ett `PATCH` mot en rad
 * där `helpful` är sant svarar `422 help.feedback_not_negative`. Kommentaren
 * från en gäst svarar `422 help.comment_requires_login` — ytan visar aldrig
 * fältet för en gäst, men rutten litar inte på ytan.
 *
 * Felkropparna är API-höljet (AGENTS.md § Felformat i API:et), också för en
 * rutt i web.php: ytan hämtar dem med `fetch` och översätter koden själv.
 * Därför byggs `404` här i stället för att `abort(404)` får rendera en
 * HTML-sida.
 *
 * Strypningen ligger i `throttle:help-feedback` (App\Providers\
 * AppServiceProvider): 20 per timme och IP för gäster, 60 per timme och
 * person för inloggade. `ThrottleWrites` hoppar över rutter med en egen
 * begränsare, så anropet räknas en gång.
 */
class HelpFeedbackController extends Controller
{
    /** Sessionens karta artikel-id → ULID för de rader gästen äger. */
    public const SESSION_KEY = 'help_feedback';

    /**
     * Ja eller Nej. Ett andra anrop i samma session för samma artikel
     * uppdaterar raden i stället för att skapa en ny; svaret är `201` för en
     * ny rad och `200` för en uppdaterad, båda med radens ULID.
     */
    public function store(
        HelpFeedbackStoreRequest $request,
        string $article,
        HelpLibrary $library,
    ): JsonResponse {
        if ($library->catalog()->article($article) === null) {
            return ApiError::response('resource.not_found', [], 404);
        }

        $helpful = $request->boolean('helpful');
        $session = $this->sessionMap($request);
        $known = $session[$article] ?? null;
        $row = is_string($known) ? $this->rowInSession($known, $article) : null;
        $created = $row === null;

        if ($row === null) {
            $row = new HelpFeedback;
            $row->article_id = $article;
            $row->locale = (string) app()->getLocale();

            $user = $request->user();
            $row->user_id = $user === null ? null : $user->getKey();
        }

        $row->helpful = $helpful;

        // Ett Nej som blir ett Ja tar orsaken och kommentaren med sig: de
        // hör till det negativa svaret och hade annars stått kvar som en
        // osynlig, felaktig uppgift i rapporten.
        if ($helpful) {
            $row->reason = null;
            $row->comment = null;
        }

        $row->save();

        $session[$article] = $row->ulid;
        $request->session()->put(self::SESSION_KEY, $session);

        return response()->json(['ulid' => $row->ulid], $created ? 201 : 200);
    }

    /**
     * Orsak och/eller kommentar på ett Nej.
     *
     * Bara sessionens rader eller den inloggades egna nås; en annans rad och
     * en okänd ULID svarar båda `404`, så svaret avslöjar inte att raden
     * finns.
     */
    public function update(HelpFeedbackUpdateRequest $request, string $ulid): JsonResponse
    {
        $row = HelpFeedback::query()->where('ulid', $ulid)->first();

        if ($row === null || ! $this->mayChange($request, $row)) {
            return ApiError::response('resource.not_found', [], 404);
        }

        if ($row->helpful) {
            return ApiError::response('help.feedback_not_negative', [], 422);
        }

        if ($request->exists('comment') && $request->user() === null) {
            return ApiError::response('help.comment_requires_login', [], 422);
        }

        // `exists()` och inte `filled()`: ett `comment: null` eller en tom
        // sträng är ett medvetet "rensa", och ska spara null.
        if ($request->exists('reason')) {
            $row->reason = $request->input('reason');
        }

        if ($request->exists('comment')) {
            $comment = $request->input('comment');

            $row->comment = is_string($comment) && $comment !== '' ? $comment : null;
        }

        $row->save();

        return response()->json(['ulid' => $row->ulid]);
    }

    /**
     * Sessionens karta, rensad till strängar. Sessionen är klientstyrd i den
     * mening att den kommer från en kaka, så inget här litas på blint.
     *
     * @return array<string, string>
     */
    private function sessionMap(Request $request): array
    {
        $map = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($map)) {
            return [];
        }

        $clean = [];

        foreach ($map as $article => $ulid) {
            if (is_string($article) && is_string($ulid)) {
                $clean[$article] = $ulid;
            }
        }

        return $clean;
    }

    /** Raden med ULID:n, om den hör till artikeln. */
    private function rowInSession(string $ulid, string $article): ?HelpFeedback
    {
        return HelpFeedback::query()
            ->where('ulid', $ulid)
            ->where('article_id', $article)
            ->first();
    }

    /** Sessionens rad, eller den inloggades egen. */
    private function mayChange(HelpFeedbackUpdateRequest $request, HelpFeedback $row): bool
    {
        if (in_array($row->ulid, $this->sessionMap($request), true)) {
            return true;
        }

        $user = $request->user();

        return $user !== null
            && $row->user_id !== null
            && (int) $row->user_id === (int) $user->getKey();
    }
}

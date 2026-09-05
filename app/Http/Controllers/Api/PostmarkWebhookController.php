<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Postmarks webhook för studsar, spamanmälningar och avanmälningar — den
 * enda rutten i systemet som en främmande server anropar, se issue 33b och
 * [[Notiser]] § email_suppression. Skriver rader i `email_suppression`,
 * tabellen vars innehåll stoppar utskick; vem som helst som kan POSTa hit
 * kan tysta vilken adress som helst i systemet, så rutten behandlas som
 * det den är (issue 33b § Omfång).
 *
 * Autentiseringen är HTTP Basic mot en hemlighet i miljön (Beslut 2),
 * jämförd med hash_equals — samma form som
 * App\Http\Controllers\Api\Auth\MagicLinkLoginController validerar sin
 * hemlighet med. Postmark har ingen token och ligger därför utanför
 * `auth:sanctum`-gruppen, se rutten i routes/api.php.
 *
 * Okända `RecordType` (Delivery, Open, ...) besvaras med 200 och ignoreras
 * (Beslut 4): en 4xx på en händelse vi inte bryr oss om får Postmark att
 * försöka igen och till slut inaktivera webhooken — vilket tar med sig
 * studsarna vi faktiskt behöver.
 */
class PostmarkWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Autentiseringen först, validering sedan (Att se upp med): en 422
        // på en oautentiserad begäran berättar för den som gissar att
        // lösenordet stämde.
        if (! $this->authenticated($request)) {
            throw ApiException::make('auth.unauthenticated', [], 401);
        }

        $request->validate([
            'RecordType' => ['required', 'string'],
        ]);

        // validate() returnerar bara de validerade fälten, så resten av
        // Postmarks nyttolast läses ur requesten i stället.
        $this->handle($request->all());

        // 200 med tom kropp när autentiseringen stämmer, oavsett om raden
        // skrevs eller inte (Beslut 4).
        return response('', 200);
    }

    /**
     * Basic-autentiseringen mot hemligheten i config/notiser.php. Båda
     * fälten jämförs och båda måste stämma. En okonfigurerad hemlighet
     * avvisar allt — `hash_equals` kräver strängar och ett tomt env() ska
     * aldrig kunna bli en öppen rutt (Beslut 2).
     */
    private function authenticated(Request $request): bool
    {
        $user = config('notiser.postmark.webhook_user');
        $password = config('notiser.postmark.webhook_password');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            return false;
        }

        $givenUser = $request->getUser();
        $givenPassword = $request->getPassword();

        if ($givenUser === null || $givenPassword === null) {
            return false;
        }

        return hash_equals($user, $givenUser) && hash_equals($password, $givenPassword);
    }

    /**
     * Skriver eller tar bort raden för det utfall Postmark rapporterar.
     * En mjuk studs undertrycker aldrig — en full inkorg är ett skäl att
     * försöka igen imorgon, inte att sluta mejla någon för alltid (Beslut 3).
     */
    private function handle(array $data): void
    {
        switch ($data['RecordType']) {
            case 'Bounce':
                if (($data['Type'] ?? null) === 'HardBounce') {
                    $this->suppress($this->address($data), EmailSuppression::REASON_HARD_BOUNCE);
                }
                break;

            case 'SpamComplaint':
                $this->suppress($this->address($data), EmailSuppression::REASON_SPAM_COMPLAINT);
                break;

            case 'SubscriptionChange':
                if (($data['SuppressSending'] ?? false) === true) {
                    $this->suppress($this->address($data), EmailSuppression::REASON_UNSUBSCRIBE);
                } else {
                    // SuppressSending = false betyder att någon återaktiverat
                    // adressen i Postmarks gränssnitt — utan borttagningen
                    // finns ingen väg tillbaka för en adress som undertryckts
                    // av misstag (Beslut 5).
                    $this->unsuppress($this->address($data));
                }
                break;

            default:
                Log::info('postmark.webhook.ignored', [
                    'record_type' => $data['RecordType'],
                ]);
        }
    }

    /**
     * Adressen ur nyttolasten — Postmark använder `Email` för Bounce och
     * SpamComplaint men `Recipient` för SubscriptionChange. Läs båda och ta
     * den som finns (Beslut 3).
     */
    private function address(array $data): ?string
    {
        foreach (['Email', 'Recipient'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }

    /**
     * Upsert i stället för create: webhookar levereras om, och samma händelse
     * kommer två gånger oftare än man tror (Beslut 5). Adressen normaliseras
     * här — 33a:s normalize() ska anropas, inte upprepas (Att se upp med).
     */
    private function suppress(?string $email, string $reason): void
    {
        if ($email === null) {
            return;
        }

        EmailSuppression::updateOrCreate(
            ['email' => EmailSuppression::normalize($email)],
            ['reason' => $reason],
        );
    }

    private function unsuppress(?string $email): void
    {
        if ($email === null) {
            return;
        }

        EmailSuppression::where('email', EmailSuppression::normalize($email))->delete();
    }
}

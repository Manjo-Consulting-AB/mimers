<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Mailguns webhook för studsar, spamanmälningar och avanmälningar — den
 * enda rutten i systemet som en främmande server anropar, se issue 38b och
 * [[Notiser]] § email_suppression. Skriver rader i `email_suppression`,
 * tabellen vars innehåll stoppar utskick; vem som helst som kan POSTa hit
 * kan tysta vilken adress som helst i systemet, så rutten behandlas som
 * det den är (issue 38b § Omfång).
 *
 * Autentiseringen är Mailguns HMAC-SHA256-signatur över `timestamp` +
 * `token`, verifierad mot kontots signeringsnyckel med hash_equals (Beslut
 * 2). Nyckeln ligger i config/notiser.php; är den inte konfigurerad avvisar
 * rutten allt. Mailgun har ingen token och ligger därför utanför
 * `auth:sanctum`-gruppen, se rutten i routes/api.php.
 *
 * Mailgun skickar ingen händelse när en undertryckning tas bort (Beslut 5):
 * en adress som spärrats av misstag släpps manuellt, genom att raden här
 * och motsvarande rad i Mailguns spärrlista raderas. Det finns ingen väg
 * tillbaka i koden — leta inte efter en.
 *
 * Okända `event` (delivered, opened, accepted, clicked, ...) besvaras med
 * 200 och ignoreras (Beslut 6): en 4xx på en händelse vi inte bryr oss om
 * får Mailgun att försöka igen och till slut inaktivera webhooken — vilket
 * tar med sig studsarna vi faktiskt behöver.
 */
class MailgunWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Autentiseringen först, validering sedan (Att se upp med): en 422
        // på en oautentiserad begäran berättar för den som gissar att
        // signaturen stämde.
        if (! $this->authenticated($request)) {
            throw ApiException::make('auth.unauthenticated', [], 401);
        }

        $request->validate([
            'event-data.event' => ['required', 'string'],
        ]);

        // validate() returnerar bara de validerade fälten, så resten av
        // Mailguns nyttolast läses ur requesten i stället.
        $this->handle($request->all());

        // 200 med tom kropp när signaturen stämmer, oavsett om raden skrevs
        // eller inte (Beslut 6).
        return response('', 200);
    }

    /**
     * Mailguns HMAC-signatur mot nyckeln i config/notiser.php. En
     * okonfigurerad nyckel avvisar allt — `hash_equals` kräver strängar och
     * ett tomt env() som HMAC-nyckel ger en giltig signatur för den som
     * känner till att nyckeln är tom (Beslut 2). Samma fail-closed-regel som
     * 33b, samma felkod: `auth.unauthenticated`.
     */
    private function authenticated(Request $request): bool
    {
        $signingKey = config('notiser.mailgun.webhook_signing_key');

        if (! is_string($signingKey) || $signingKey === '') {
            return false;
        }

        $timestamp = $request->input('signature.timestamp');
        $token = $request->input('signature.token');
        $given = $request->input('signature.signature');

        if (! is_string($timestamp) || $timestamp === '' || ! is_string($token) || $token === '' || ! is_string($given) || $given === '') {
            return false;
        }

        // Tidsstämpeln måste ligga inom fönstret, annars går en fångad
        // signatur att spela om i all framtid (Beslut 3). Fönstret är
        // generöst med flit — Mailgun kan fördröja webhookar i sin kö.
        $tolerance = (int) config('notiser.mailgun.webhook_tolerance_seconds', 900);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        // Ingen tokencache här: skrivningarna är upsertar på en adress ur
        // nyttolasten, så en omspelad händelse ger exakt samma rad som
        // första gången. En cache med tillstånd som ska rensas är mer kod
        // och en ny felkälla för ett problem som inte finns (Beslut 3).
        $expected = hash_hmac('sha256', $timestamp.$token, $signingKey);

        // Känt värde först, okänt sedan — argumentordningen spelar roll för
        // läsbarheten (Beslut 2).
        return hash_equals($expected, $given);
    }

    /**
     * Skriver raden för det utfall Mailgun rapporterar. Nyttolasten ligger
     * under `event-data` — nyckeln har bindestreck, inte understreck — och
     * adressen är `recipient` för alla typerna (Beslut 4). En temporär
     * misslyckad leverans undertrycker aldrig: en full inkorg är ett skäl att
     * försöka igen imorgon, inte att sluta mejla någon för alltid.
     */
    private function handle(array $data): void
    {
        $eventData = $data['event-data'] ?? [];
        $event = $eventData['event'] ?? null;
        $recipient = $eventData['recipient'] ?? null;

        switch ($event) {
            case 'failed':
                if (($eventData['severity'] ?? null) === 'permanent') {
                    $this->suppress($recipient, EmailSuppression::REASON_HARD_BOUNCE);
                }
                break;

            case 'complained':
                $this->suppress($recipient, EmailSuppression::REASON_SPAM_COMPLAINT);
                break;

            case 'unsubscribed':
                $this->suppress($recipient, EmailSuppression::REASON_UNSUBSCRIBE);
                break;

            default:
                Log::info('mailgun.webhook.ignored', [
                    'event' => is_string($event) ? $event : 'unknown',
                ]);
        }
    }

    /**
     * Upsert i stället för create: webhookar levereras om, och samma händelse
     * kommer två gånger oftare än man tror (Beslut 4). Adressen normaliseras
     * här — 33a:s normalize() ska anropas, inte upprepas (Att se upp med).
     */
    private function suppress(mixed $email, string $reason): void
    {
        if (! is_string($email) || $email === '') {
            return;
        }

        EmailSuppression::updateOrCreate(
            ['email' => EmailSuppression::normalize($email)],
            ['reason' => $reason],
        );
    }
}

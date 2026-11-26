<?php

namespace App\Support\Notification;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Förvalen och uppslaget i notification_preference — den enda plats som
 * formulerar "vad vill den här användaren ha för den här typen och kanalen"
 * (issue 31a § Beslut 3). Se [[Notiser]] § notification_preference:
 * "Saknad rad = förvalt värde i kod", så förvalen ligger här, inte som rader
 * i databasen (Beslut 2).
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Plan\Entitlements och de actions [[ADR-0024 Tunna controllers
 * och actions]] beskriver.
 *
 * Preferenserna styr kanalerna `email` och `in_app`, aldrig `webhook`
 * (Beslut 4): en webhook är en integration ett KONTO registrerat, med sina
 * egna `event_types` — den frågar aldrig den här tabellen. 37b lägger
 * webhookraderna på sin egen grund.
 *
 * **`in_app` kom med issue 383** ([[ADR-0077 Notislistan i appen]] § 4).
 * Kanalen har ett eget förval per typ: påslaget för de typer som listas i
 * IN_APP_DEFAULTS (issue 386), avslaget för alla andra. `digest` är alltid falsk för
 * `in_app` — en rad i listan är ingen sammanfattning.
 */
final class NotificationPreferences
{
    /**
     * Förvalen per typ, för kanalen `email` (Beslut 3). En rad i databasen
     * ersätter hela raden här. `digest = true` är standard för
     * uppgiftspåminnelserna — [[Notiser]] § notification_preference:
     * produkten är säsongsbetonad och tjugo separata mejl på en förmiddag
     * ger en avprenumeration.
     *
     * Ordningen är API-synlig: `array_keys()` på den här konstanten är
     * listan preferensytan ritar (issue 31b § Beslut 2), i samma ordning som
     * typkonstanterna deklareras i App\Models\Notification. Flytta inte om.
     *
     * @var array<string, array{enabled: bool, digest: bool}>
     */
    private const EMAIL_DEFAULTS = [
        Notification::TYPE_TASK_DUE => ['enabled' => true, 'digest' => true],
        Notification::TYPE_TASK_OVERDUE => ['enabled' => true, 'digest' => true],
        Notification::TYPE_LOAN_DUE => ['enabled' => true, 'digest' => false],
        Notification::TYPE_QUOTA_WARNING => ['enabled' => true, 'digest' => false],
        Notification::TYPE_TRANSFER_REQUESTED => ['enabled' => true, 'digest' => false],
        Notification::TYPE_ACCOUNT_INACTIVE => ['enabled' => true, 'digest' => false],
        // `container.deleted` är på och levereras direkt, som
        // `transfer.requested` (issue 1235). Typen borde inte gå att stänga
        // av — den säger att åtkomsten redan är borta — men
        // preferensmodellen har inget "obligatorisk"-läge, så den ligger här
        // och kan stängas av som de andra. Se PR:ens Frågor och antaganden.
        Notification::TYPE_CONTAINER_DELETED => ['enabled' => true, 'digest' => false],
        // `agent.deleted` kan stängas av i mejlet men aldrig i appen — se
        // MANDATORY_IN_APP_TYPES nedan (issue 406 · [[ADR-0087 MCP-server för
        // agenter]] Riktning 13 och 14).
        Notification::TYPE_AGENT_DELETED => ['enabled' => true, 'digest' => false],
    ];

    /**
     * Typerna som kan ha kanalen `in_app`, med förvalet för den
     * ([[ADR-0077 Notislistan i appen]] § 4). Den första omgången är
     * `container.deleted`, `transfer.requested` och `quota.warning`, alla
     * påslagna. `task.due` och `task.overdue` får kanalen senare. En typ som
     * inte står här har kanalen avslagen och visar ingen växel för den.
     *
     * Det här är den enda listan: inställningssidan och API:et läser den via
     * inAppTypes() och förvalet slås upp härifrån. En typ här som inte står
     * i EMAIL_DEFAULTS (`versioning.skipped`, issue 412) får förvalet men
     * ingen växel: inAppTypes() hämtar bara typer ur preferensytans lista,
     * så kanalen går inte att stänga av — den hör till notisen.
     *
     * @var array<string, array{enabled: bool, digest: bool}>
     */
    private const IN_APP_DEFAULTS = [
        Notification::TYPE_QUOTA_WARNING => ['enabled' => true, 'digest' => false],
        Notification::TYPE_TRANSFER_REQUESTED => ['enabled' => true, 'digest' => false],
        Notification::TYPE_CONTAINER_DELETED => ['enabled' => true, 'digest' => false],
        // Issue 412: ägaren får veta att lagringen är full i appen. Typen
        // står INTE i EMAIL_DEFAULTS och har därför ingen växel på
        // inställningssidan — den är ingen mejltyp (se NO_EMAIL_TYPES).
        Notification::TYPE_VERSIONING_SKIPPED => ['enabled' => true, 'digest' => false],
        // Issue 1518: pärmen är klar. Samma form som `versioning.skipped`:
        // bara i appen, ingen växel (se NO_EMAIL_TYPES).
        Notification::TYPE_BINDER_READY => ['enabled' => true, 'digest' => false],
        // Issue 1529: någon lämnade containern. Bara i appen, ingen växel
        // (se NO_EMAIL_TYPES).
        Notification::TYPE_ACCESS_LEFT => ['enabled' => true, 'digest' => false],
        // Issue 1701: kontoexporten är klar. Förvalet för appen är på. Typen
        // står INTE i EMAIL_DEFAULTS och har ingen växel på inställningssidan,
        // som `container.content_moving`: mejlet följer UNKNOWN_DEFAULT (på,
        // direkt) och en sparad rad i notification_preference gäller.
        Notification::TYPE_ACCOUNT_EXPORT_READY => ['enabled' => true, 'digest' => false],
    ];

    /**
     * Okänd typ: `enabled = true`, `digest = false` (Beslut 3). Ett förval
     * som tystar det okända tappar notiser den dag M8 lägger till en typ och
     * glömmer raden här.
     *
     * @var array{enabled: bool, digest: bool}
     */
    private const UNKNOWN_DEFAULT = ['enabled' => true, 'digest' => false];

    /**
     * Typer som alltid går som mejl, oavsett vad preferensmodellen säger, och
     * aldrig samlas i veckosammanfattningen. `agent.connected` är den första,
     * se [[ADR-0087 MCP-server för agenter]] Riktning 14 och
     * [[Notiser]] § notification: "kan inte stängas av eller samlas i
     * `digest`" — mejlet är det som visar att någon anslöt en klient, och en
     * avstängd kanal hade gjort en kapad session tyst.
     *
     * **De står inte i EMAIL_DEFAULTS.** Listan där är preferensytans
     * nycklar (issue 31b § Beslut 2), och en typ som inte går att välja ska
     * inte ritas som en växel. Att ett försök att stänga av den ändå inte
     * biter prövas här i stället för i vyn.
     *
     * @var array<int, string>
     */
    private const MANDATORY_EMAIL_TYPES = [
        Notification::TYPE_AGENT_CONNECTED,
        // `agent.access_lost` (issue 409): agenten har tappat åtkomsten och
        // användaren ska få veta det, inte välja bort det.
        Notification::TYPE_AGENT_ACCESS_LOST,
        // `account.downgrade_warning` (issue 1457): varningen säger att
        // innehållet kan raderas i steg 4, och en avstängd kanal hade gjort
        // raderingen tyst.
        Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING,
    ];

    /**
     * Typer som ALDRIG går som mejl. `versioning.skipped` är den första
     * (issue 412 · [[ADR-0088 Versionshistorik för items]] Riktning 3): den
     * går till ägaren i appen, och en sparad rad med `email` ska inte kunna
     * ändra det. Typen står inte i EMAIL_DEFAULTS, så utan den här listan
     * hade UNKNOWN_DEFAULT gett den e-post på som förval — och en rad i
     * `notification_preference` hade kunnat slå på den.
     *
     * @var list<string>
     */
    private const NO_EMAIL_TYPES = [
        Notification::TYPE_VERSIONING_SKIPPED,
        Notification::TYPE_BINDER_READY,
        Notification::TYPE_ACCESS_LEFT,
    ];

    /**
     * Typer vars `in_app`-kanal aldrig kan stängas av.
     *
     * `agent.deleted` är den första (issue 406 · [[ADR-0087 MCP-server för
     * agenter]] Riktning 13 och 14): användarens LÄSNING av notisen är det som
     * släpper agentens raderingstak, så en avstängd appkanal hade låst taket
     * för alltid. Mejlet kan stängas av som vanligt — det bär ingen nyckel.
     *
     * Typerna står INTE i IN_APP_DEFAULTS: listan där är växlarna
     * inställningssidan ritar, och en kanal som inte går att stänga av ska
     * inte ritas som en växel. Förvalet formuleras i stället här, i
     * isEnabled().
     *
     * @var list<string>
     */
    private const MANDATORY_IN_APP_TYPES = [
        Notification::TYPE_AGENT_DELETED,
        Notification::TYPE_AGENT_ACCESS_LOST,
        // `account.downgrade_warning` (issue 1457) varnar för radering och
        // ska nå ägaren även om hon stängt av kanalen.
        Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING,
    ];

    /**
     * Typerna preferensytan handlar om — nycklarna i EMAIL_DEFAULTS, i den
     * ordning listan ska ritas (issue 31b § Beslut 2). Typnamnrymden är öppen
     * (issue 30 § Beslut 4), så det här är listan över KÄNDA typer, inte en
     * uttömmande lista över vad `notification.type` kan bära.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::EMAIL_DEFAULTS);
    }

    /**
     * Typerna som kan ha kanalen `in_app`, i `types()`-ordning.
     *
     * @return list<string>
     */
    public function inAppTypes(): array
    {
        return array_values(array_filter(
            $this->types(),
            fn (string $type): bool => isset(self::IN_APP_DEFAULTS[$type]),
        ));
    }

    /**
     * Kanalerna en notis av den här typen ska levereras på, för den här
     * mottagaren. Varje PÅSLAGEN kanal returneras — `email` och `in_app` —
     * i den ordningen, så anroparen aldrig behöver känna till kanalerna
     * själv (issue 383). `digest` påverkar inte valet här: e-postens
     * sammanfattningsflagga sätts på leveransraden av CreateNotification.
     *
     * `$user = null` (ren kontonotis) får aldrig `in_app`: det finns ingen
     * person att visa raden för. E-posten följer förvalet som förut.
     *
     * @return list<string>
     */
    public function channelsFor(?User $user, string $type): array
    {
        $channels = [];

        if ($this->isEnabled($user, $type, NotificationDelivery::CHANNEL_EMAIL)) {
            $channels[] = NotificationDelivery::CHANNEL_EMAIL;
        }

        if ($user !== null && $this->isEnabled($user, $type, NotificationDelivery::CHANNEL_IN_APP)) {
            $channels[] = NotificationDelivery::CHANNEL_IN_APP;
        }

        return $channels;
    }

    /**
     * Är kanalen påslagen för den här typen och användaren? En saknad rad
     * betyder förvalet i kod (Beslut 2); en rad ersätter förvalet i sin helhet.
     */
    public function isEnabled(?User $user, string $type, string $channel): bool
    {
        // En typ som aldrig går som mejl: varken förvalet eller en sparad
        // rad får slå på kanalen. Se NO_EMAIL_TYPES.
        if ($channel === NotificationDelivery::CHANNEL_EMAIL && in_array($type, self::NO_EMAIL_TYPES, true)) {
            return false;
        }

        // Ett obligatoriskt mejl går före varje rad: `agent.connected` är
        // säkerhetsinformation om en anslutning, inte en prenumeration.
        if ($channel === NotificationDelivery::CHANNEL_EMAIL && $this->isMandatoryEmail($type)) {
            return true;
        }

        // Appkanalen för en typ som inte får stängas av: en sparad rad med
        // enabled = false biter inte. Se MANDATORY_IN_APP_TYPES.
        if ($channel === NotificationDelivery::CHANNEL_IN_APP && $this->isMandatoryInApp($type)) {
            return true;
        }

        return $this->resolve($user, $type, $channel)['enabled'];
    }

    /**
     * Går typen alltid som mejl? Används av App\Mail\NotificationMail för att
     * inte sätta en avregistreringslänk på ett mejl som ändå inte kan stängas
     * av — en One-Click-länk som leder till 404 är sämre än ingen länk
     * (RFC 8058).
     */
    public function isMandatoryEmail(string $type): bool
    {
        return in_array($type, self::MANDATORY_EMAIL_TYPES, true);
    }

    /**
     * Kan typens `in_app`-kanal stängas av? Se MANDATORY_IN_APP_TYPES.
     */
    public function isMandatoryInApp(string $type): bool
    {
        return in_array($type, self::MANDATORY_IN_APP_TYPES, true);
    }

    /**
     * Ska notiser av den här typen samlas i veckosammanfattningen i stället
     * för att levereras direkt? Läses av 35; i den här issuen skapar
     * `digest = true` en leveransrad precis som allt annat (Beslut 7).
     *
     * **Alltid falskt för `in_app`** ([[ADR-0077 Notislistan i appen]] § 4):
     * listan är ett flöde av enskilda rader, inte en sammanfattning. Värdet
     * läses från förvalet eller raden bara för e-post, så en rad som någon
     * skrivit med `digest = true` på kanalen kan aldrig få genomslag.
     */
    public function digest(?User $user, string $type, string $channel): bool
    {
        if ($channel === NotificationDelivery::CHANNEL_IN_APP) {
            return false;
        }

        if ($this->isMandatoryEmail($type)) {
            return false;
        }

        return $this->resolve($user, $type, $channel)['digest'];
    }

    /**
     * Det effektiva valet för en (användare, typ, kanal): raden om den finns,
     * annars förvalet. `$user = null` (ren kontonotis) har inga preferensrader
     * att fråga och får alltid förvalet.
     *
     * @return array{enabled: bool, digest: bool}
     */
    private function resolve(?User $user, string $type, string $channel): array
    {
        if ($user !== null) {
            $row = NotificationPreference::query()
                ->where('user_id', $user->getKey())
                ->where('type', $type)
                ->where('channel', $channel)
                ->first();

            if ($row !== null) {
                return ['enabled' => $row->enabled, 'digest' => $row->digest];
            }
        }

        return $this->defaultsFor($type, $channel);
    }

    /**
     * @return array{enabled: bool, digest: bool}
     */
    private function defaultsFor(string $type, string $channel): array
    {
        if ($channel === NotificationDelivery::CHANNEL_EMAIL && isset(self::EMAIL_DEFAULTS[$type])) {
            return self::EMAIL_DEFAULTS[$type];
        }

        if ($channel === NotificationDelivery::CHANNEL_IN_APP) {
            // Påslagen bara för typerna i IN_APP_DEFAULTS; okända typer får
            // aldrig kanalen av misstag.
            return self::IN_APP_DEFAULTS[$type] ?? ['enabled' => false, 'digest' => false];
        }

        return self::UNKNOWN_DEFAULT;
    }
}

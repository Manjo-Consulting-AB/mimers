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
 * Kanalen har ett eget förval per typ, avslaget för alla i den här issuen;
 * 386 slår på det för de första typerna. `digest` är alltid falsk för
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
        return $this->resolve($user, $type, $channel)['enabled'];
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
            // Avslagen för VARJE typ i issue 383 — kanalen finns, men ingen
            // typ har den påslagen förrän 386 lägger till de första tre
            // ([[ADR-0077 Notislistan i appen]] § 4). Ett "på" här hade
            // fyllt listan med rader ingen bett om, och okända typer ska
            // aldrig få kanalen av misstag.
            return ['enabled' => false, 'digest' => false];
        }

        return self::UNKNOWN_DEFAULT;
    }
}

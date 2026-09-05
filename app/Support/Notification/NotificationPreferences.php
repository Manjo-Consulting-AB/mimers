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
 * Preferenserna styr kanalen `email`, aldrig `webhook` (Beslut 4): en webhook
 * är en integration ett KONTO registrerat, med sina egna `event_types` — den
 * frågar aldrig den här tabellen. 37b lägger webhookraderna på sin egen grund.
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
     * @var array<string, array{enabled: bool, digest: bool}>
     */
    private const EMAIL_DEFAULTS = [
        Notification::TYPE_TASK_DUE => ['enabled' => true, 'digest' => true],
        Notification::TYPE_TASK_OVERDUE => ['enabled' => true, 'digest' => true],
        Notification::TYPE_LOAN_DUE => ['enabled' => true, 'digest' => false],
        Notification::TYPE_QUOTA_WARNING => ['enabled' => true, 'digest' => false],
        Notification::TYPE_ACCOUNT_INACTIVE => ['enabled' => true, 'digest' => false],
        Notification::TYPE_INVITATION_RECEIVED => ['enabled' => true, 'digest' => false],
        Notification::TYPE_TRANSFER_REQUESTED => ['enabled' => true, 'digest' => false],
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
     * Kanalerna en notis av den här typen ska levereras på, för den här
     * mottagaren. I den här issuen är e-post den enda kanal som frågar
     * preferenserna (Beslut 4, Beslut 6) — webhooken läggs av 37b på sin egen
     * grund.
     *
     * @return list<string>
     */
    public function channelsFor(?User $user, string $type): array
    {
        return $this->isEnabled($user, $type, NotificationDelivery::CHANNEL_EMAIL)
            ? [NotificationDelivery::CHANNEL_EMAIL]
            : [];
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
     */
    public function digest(?User $user, string $type, string $channel): bool
    {
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

        return self::UNKNOWN_DEFAULT;
    }
}

<?php

namespace App\Models;

use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * En undertryckt e-postadress — "sluta mejla den här brevlådan", se
 * [[Notiser]] § email_suppression och issue 33a. Tabellen matas av Postmarks
 * webhook (33b); den här issuen bygger tabellen, uppslaget och spärren i
 * App\Support\Notification\EmailChannel.
 *
 * Undertryckningen gäller ADRESSEN, inte personen (issue 33a § Beslut 1):
 * samma adress kan höra till flera användare i flera konton, och en hård
 * studs gör den oanvändbar för alla. Därför varken `account_id`, `user_id`,
 * `ulid` eller `deleted_at` — raden syns aldrig i API:et, och en
 * undertryckning som ska bort tas bort på riktigt.
 *
 * Adressen normaliseras (trim + gemener) vid BÅDE skrivning och uppslag
 * (Beslut 2) — `Anna@Example.COM` och `anna@example.com` är samma brevlåda
 * hos varje operatör som betyder något, och två rader för samma adress gör
 * uniknyckeln verkningslös. Normaliseringen bor i normalize() och anropas av
 * skrivhändelsen nedan; anropande kod ska aldrig skriva `strtolower()` själv.
 *
 * `reason` är en sluten mängd (Beslut 1) — Postmarks tre utfall och
 * ingenting annat. Konstanterna är det enda stället strängarna stavas;
 * 33b läser dem när webhooken skriver rader.
 */
#[Fillable(['email', 'reason'])]
class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
    use HasFactory;

    /**
     * Tabellen heter `email_suppression`, inte Eloquents standardplural.
     */
    protected $table = 'email_suppression';

    public const REASON_HARD_BOUNCE = 'hard_bounce';

    public const REASON_SPAM_COMPLAINT = 'spam_complaint';

    public const REASON_UNSUBSCRIBE = 'unsubscribe';

    /**
     * De giltiga skälen, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const REASONS = [self::REASON_HARD_BOUNCE, self::REASON_SPAM_COMPLAINT, self::REASON_UNSUBSCRIBE];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->email = self::normalize($model->email);
        });
    }

    /**
     * Adressen som den ska lagras och slås upp: trimmad och i gemener
     * (Beslut 2). Den enda platsen normaliseringen bor.
     */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Är adressen undertryckt? En enkel exists()-fråga på uniknyckeln — den
     * här metoden körs en gång per e-postleverans i 34a:s loop, så den får
     * varken hämta raden eller hydrera en modell (issue 33a § Att se upp med).
     */
    public static function isSuppressed(string $email): bool
    {
        return static::query()->where('email', static::normalize($email))->exists();
    }

    /**
     * Skälet adressen är undertryckt — null om den inte är det. Läses bara av
     * EmailChannel vid kastet, för Log::warning-kontexten (issue 33a
     * § Beslut 6); den följer samma normalisering som isSuppressed().
     */
    public static function reasonFor(string $email): ?string
    {
        return static::query()->where('email', static::normalize($email))->value('reason');
    }
}

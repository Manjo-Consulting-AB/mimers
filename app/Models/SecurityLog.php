<?php

namespace App\Models;

use Database\Factories\SecurityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Säkerhetsloggens rad — systemets anteckning om missbruk, intrång och
 * olagligt innehåll, se [[ADR-0043 Tre loggar]] § Säkerhetsloggen och issue
 * 113. Den ENDA vägen in är App\Actions\Security\RecordSecurityEvent, så
 * allt är UTESLUTET ur `#[Fillable]` och sätts explicit av actionen — samma
 * resonemang som App\Models\AuditLog.
 *
 * **Ingen rå IP-adress och ingen rå webbläsarsträng finns i den här
 * modellen.** `ip_group` är pseudonymen App\Support\Security\IpGroup räknar
 * fram ur `config('app.key')`, och `device_name` är webbläsarsträngen tolkad
 * till ett kort namn av App\Support\Security\DeviceName. Tolkningen sker i
 * actionen, innan raden skrivs; strängen och adressen kastas.
 *
 * Ingen `updated_at` och ingen `deleted_at`: en logg som kan ändras är inget
 * bevis, och en rad tas bort hel eller inte alls — av gallringen i issue 115,
 * tolv månader efter `created_at`.
 *
 * `action` är ett ÖPPET namnrum, som `audit_log.action`: konstanterna nedan
 * finns så att anroparna aldrig stavar en sträng, men `action`-kolumnen har
 * inget CHECK-villkor och fler händelser kan komma utan en migrering.
 *
 * `meta` bär data om händelsen — nivåer, antal, ULID:er — och **aldrig ett
 * lösenord, en kod eller ett token, inte ens hashad**, och aldrig en
 * e-postadress (issue 113).
 */
#[Fillable([])]
class SecurityLog extends Model
{
    /** @use HasFactory<SecurityLogFactory> */
    use HasFactory;

    /**
     * Tabellen heter `security_log`, inte Eloquents standardplural.
     */
    protected $table = 'security_log';

    /**
     * Säkerhetsloggen har ingen `updated_at` — se klassdocblocket.
     */
    public const UPDATED_AT = null;

    /**
     * En lyckad inloggning — lösenord eller magic link, webben eller API:t.
     */
    public const ACTION_LOGIN = 'auth.login';

    /**
     * En misslyckad inloggning: fel uppgifter, eller fel engångskod. Raden
     * bär användaren när adressen finns och ingen användare när den inte
     * gör — **aldrig adressen** någon försökte logga in med.
     */
    public const ACTION_LOGIN_FAILED = 'auth.login_failed';

    /**
     * Ett inlöst magic link. Raden skrivs när inloggningen fullbordas, inte
     * när länken öppnas: ett konto med bekräftad tvåfaktor loggar inte in
     * förrän koden är prövad (issue 80).
     */
    public const ACTION_MAGIC_LINK = 'auth.magic_link';

    /**
     * Tvåfaktor slogs på — den bekräftade aktiveringen, inte den påbörjade.
     */
    public const ACTION_TOTP_ENABLED = 'auth.totp_enabled';

    /**
     * Tvåfaktor slogs av.
     */
    public const ACTION_TOTP_DISABLED = 'auth.totp_disabled';

    /**
     * Nya återställningskoder utfärdade. Kodsatsen själv finns inte i raden.
     */
    public const ACTION_RECOVERY_CODES = 'auth.recovery_codes';

    /**
     * Lösenordet byttes — eller sattes för första gången av ett konto som
     * bara använt magic link (issue 129).
     *
     * **`meta` säger bara `had_password`**, alltså om ett lösenord fanns
     * FÖRE bytet. Varken det gamla eller det nya lösenordet, och ingen kod,
     * finns i raden — samma regel som för tvåfaktorraderna ovan.
     */
    public const ACTION_PASSWORD_CHANGED = 'auth.password_changed';

    /**
     * En inbjudan skickades.
     */
    public const ACTION_INVITATION_CREATED = 'invitation.created';

    /**
     * En export beställdes.
     */
    public const ACTION_EXPORT_REQUESTED = 'export.requested';

    /**
     * En färdig export hämtades.
     */
    public const ACTION_EXPORT_DOWNLOADED = 'export.downloaded';

    /**
     * En webhook skapades.
     */
    public const ACTION_WEBHOOK_CREATED = 'webhook.created';

    /**
     * En webhook togs bort.
     */
    public const ACTION_WEBHOOK_DELETED = 'webhook.deleted';

    /**
     * Lagringen tömdes — bilagorna flyttades till papperskorgen.
     */
    public const ACTION_STORAGE_EMPTIED = 'storage.emptied';

    /**
     * En fil hämtades ur en container användaren inte äger. **Den enda
     * läsning som loggas** ([[ADR-0043 Tre loggar]] § Säkerhetsloggen): det
     * är genom nedladdningen innehåll lämnar sin ägare.
     */
    public const ACTION_ATTACHMENT_DOWNLOADED = 'attachment.downloaded';

    /**
     * En rättslig spärr sattes (issue 112).
     */
    public const ACTION_LEGAL_HOLD_PLACED = 'legal_hold.placed';

    /**
     * En rättslig spärr hävdes (issue 112).
     */
    public const ACTION_LEGAL_HOLD_LIFTED = 'legal_hold.lifted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Kontot händelsen rör — ägarkontot för containern en nedladdning kom
     * ur, kontot en spärr sattes på, eller kontot användaren handlade i.
     * Null för en misslyckad inloggning mot en adress som inte finns.
     *
     * Kolumnen är en identifierare utan främmande nyckel (se migrationen),
     * som `audit_log.account_id`, men relationen finns för den som läser en
     * rad för hand.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Användaren som handlade. Null när ingen är känd: en misslyckad
     * inloggning mot en adress som inte finns, och den rättsliga spärren,
     * som sätts från kommandoraden utan en inloggad användare.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

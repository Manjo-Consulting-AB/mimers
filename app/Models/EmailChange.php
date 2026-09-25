<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En begärd adressändring, se [[M20 Kontot]] § 130 och
 * [[Konton och åtkomst]] § email_change. Radens data läses och skrivs
 * uteslutande via App\Actions\Account\RequestEmailChange och
 * App\Actions\Account\ConfirmEmailChange — modellen bär inget beteende
 * utöver casts och de två frågorna om radens tillstånd.
 *
 * **`token_hash` är alltid en SHA-256-hex av slumpen i länken, aldrig
 * slumpen själv**, precis som App\Models\MagicLinkToken. `Hidden` är ett
 * skyddsnät utifall raden någonsin serialiseras — ingen kontroller
 * returnerar en EmailChange, och `new_email` är det enda fält en vy någonsin
 * skulle behöva.
 *
 * **`isExpired()` och `isConfirmed()` är de två frågorna länken ställer**,
 * och de bor här så att ConfirmEmailChange inte formulerar om dem. En
 * bekräftad rad är förbrukad: `confirmed_at` satt = bytet gjordes, och en
 * andra öppning av samma länk är ett återanrop av ett engångstoken.
 */
#[Fillable(['user_id', 'new_email', 'token_hash', 'expires_at', 'confirmed_at'])]
#[Hidden(['token_hash'])]
class EmailChange extends Model
{
    /**
     * Tabellen heter `email_change`, i singular liksom `account`, `user` och
     * `magic_link_token` — se AGENTS.md § Databaskonventioner och
     * database/migrations/2026_09_25_010000_create_email_change_table.php.
     */
    protected $table = 'email_change';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Giltig så länge `expires_at` ligger i FRAMTIDEN — alltså `isFuture()`
     * och inte `isPast()`.
     *
     * Skillnaden är inte kosmetisk. En ny begäran ogiltigförklarar en
     * tidigare obekräftad rad genom att sätta dess `expires_at` till NU
     * (issuens flödespunkt 2), och med `isPast()` hade den raden varit giltig
     * i samma sekund som den ogiltigförklarades: `isPast()` är strängt mindre
     * än, och två anrop inom samma sekund ger exakt likhet. Med den här
     * läsningen betyder `expires_at` "sista giltiga ögonblick", vilket är vad
     * både utgången efter en timme och ogiltigförklaringen behöver.
     */
    public function isExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Personen bytet gäller. Ingen `Restrict`-regel i koden: den främmande
     * nyckeln bär den (se migrationen).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

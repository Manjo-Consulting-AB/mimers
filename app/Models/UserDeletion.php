<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En begärd personradering, se [[M22 Redo för testare]] § 145 och
 * [[Konton och åtkomst]] § user_deletion. Radens data läses och skrivs
 * uteslutande via App\Actions\User\RequestUserDeletion och
 * App\Actions\User\ConfirmUserDeletion — modellen bär inget beteende utöver
 * casts och de två frågorna om radens tillstånd.
 *
 * **`token_hash` är alltid en SHA-256-hex av slumpen i länken, aldrig
 * slumpen själv**, precis som App\Models\PasswordChange och
 * App\Models\EmailChange. `Hidden` är ett skyddsnät utifall raden någonsin
 * serialiseras — ingen kontroller returnerar en UserDeletion.
 *
 * **Raden bär ingen nyttolast.** Till skillnad från `password_change`, som
 * lagrar den nya hashen, och `email_change`, som lagrar den nya adressen,
 * har en personradering ingenting att komma med: det som ska hända står i
 * `user`-raden och i medlemskapen, och App\Actions\User\DeleteUser räknar om
 * det när länken öppnas. `Fillable` är därför kort, och en nyttolast hade
 * varit en kopia som kunde glida isär från det den beskrev.
 *
 * **`isExpired()` och `isConfirmed()` är de två frågorna länken ställer**,
 * och de bor här så att ConfirmUserDeletion inte formulerar om dem. En
 * bekräftad rad är förbrukad: `confirmed_at` satt = raderingen kördes, och en
 * andra öppning av samma länk är ett återanrop av ett engångstoken — men
 * personen finns då inte längre, så raden finns inte heller.
 */
#[Fillable(['user_id', 'token_hash', 'expires_at', 'confirmed_at'])]
#[Hidden(['token_hash'])]
class UserDeletion extends Model
{
    /**
     * Tabellen heter `user_deletion`, i singular liksom `password_change`,
     * `email_change`, `account`, `user` och `magic_link_token` — se
     * AGENTS.md § Databaskonventioner och
     * database/migrations/2026_09_27_000000_create_user_deletion_table.php.
     */
    protected $table = 'user_deletion';

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
     * (RequestUserDeletion), och med `isPast()` hade den raden varit giltig i
     * samma sekund som den ogiltigförklarades: `isPast()` är strängt mindre
     * än, och två anrop inom samma sekund ger exakt likhet. Med den här
     * läsningen betyder `expires_at` "sista giltiga ögonblick", vilket är vad
     * både utgången efter en timme och ogiltigförklaringen behöver. Samma
     * läsning som App\Models\PasswordChange::isExpired().
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
     * Personen raderingen gäller. Ingen `Restrict`-regel i koden: den
     * främmande nyckeln bär den (se migrationen).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

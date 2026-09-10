<?php

namespace App\Support\Access;

/**
 * Vilka items en användare når i EN container, och på vilken nivå — svaret
 * från App\Actions\Access\ResolveItemScope, se [[ADR-0028 Åtkomst på
 * itemnivå]] § Beslut regel 1–4 och [[Konton och åtkomst]] §
 * Behörighetsregler regel 3.
 *
 * Ett VÄRDEOBJEKT i app/Support/, ingen Eloquent-scope: det beskriver ett
 * svar, det filtrerar ingen fråga. Frågescopen som filtrerar listningar ägs
 * av issue 73, se issue 70 § Beslut 1.
 *
 * Två former, och skillnaden är uttrycklig med flit:
 *
 * - **obegränsat** (`unrestricted()`) — hela containern: ägarkontots
 *   medlemmar och innehavare av en container-bred grant (`item_id IS NULL`).
 *   `itemIds()` svarar `null` och `unrestrictedLevel()` nivån.
 * - **begränsat** (`restricted()`) — bara de item som grants faktiskt når.
 *   `itemIds()` svarar listan, `unrestrictedLevel()` null.
 *
 * Att `null` och `[]` betyder olika saker är hela poängen: "alla items" och
 * "inga items" får inte kollapsa till samma värde. Issue 73 kan därför
 * hoppa över filtret helt i det vanliga fallet i stället för att
 * materialisera varje löpnummer i containern, och en tom lista är alltid
 * "når ingenting" — aldrig "når allt".
 *
 * Har mottagaren BÅDE en container-bred grant och itemgrants blir omfånget
 * obegränsat med container-nivån, och itemnivåerna följer med som en
 * golvhöjning: `levelFor()` ger max av de två, se regel 4.
 */
final class ItemScope
{
    /**
     * @param  string|null  $unrestrictedLevel  nivån som gäller varje item i containern, eller null för ett begränsat omfång
     * @param  array<int, string>  $levelByItem  item_id → nivå
     */
    private function __construct(
        private readonly ?string $unrestrictedLevel,
        private readonly array $levelByItem,
    ) {}

    /**
     * Hela containern på $level. $levelByItem är de itemgrants som
     * mottagaren DESSUTOM har — de höjer enskilda item över golvet, aldrig
     * under det (regel 4: högsta nivån vinner).
     *
     * @param  array<int, string>  $levelByItem
     */
    public static function unrestricted(string $level, array $levelByItem = []): self
    {
        return new self($level, $levelByItem);
    }

    /**
     * Bara de item som grants når. En tom lista är ett giltigt svar och
     * betyder "når ingenting" — se klassens docblock.
     *
     * @param  array<int, string>  $levelByItem  item_id → nivå
     */
    public static function restricted(array $levelByItem): self
    {
        return new self(null, $levelByItem);
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestrictedLevel !== null;
    }

    /**
     * Nivån på ett enskilt item, eller null när itemet ligger utanför
     * omfånget. Är omfånget obegränsat når varje item — nivån är då
     * container-nivån, höjd till itemets egen om den är högre.
     */
    public function levelFor(int $itemId): ?string
    {
        $level = $this->levelByItem[$itemId] ?? null;

        if ($this->unrestrictedLevel === null) {
            return $level;
        }

        return $level === null
            ? $this->unrestrictedLevel
            : AccessLevel::max($this->unrestrictedLevel, $level);
    }

    /**
     * Når användaren itemet på minst $minimum? Den fråga varje
     * ItemPolicy-metod ställer, se issue 70 § Beslut 8.
     */
    public function allows(int $itemId, string $minimum): bool
    {
        $level = $this->levelFor($itemId);

        return $level !== null && AccessLevel::atLeast($level, $minimum);
    }

    /**
     * Itemens löpnummer, eller `null` för "hela containern" — se klassens
     * docblock. Ordningen är inte specificerad och ska inte läsas som en
     * rangordning.
     *
     * @return list<int>|null
     */
    public function itemIds(): ?array
    {
        return $this->isUnrestricted() ? null : array_keys($this->levelByItem);
    }

    /**
     * Nivån som gäller varje item i containern — null för ett begränsat
     * omfång. Hårdare än isUnrestricted() kan den inte bli: ett obegränsat
     * omfång KAN bära itemgrants ovanpå (se klassens docblock), och de syns
     * bara genom levelFor().
     */
    public function unrestrictedLevel(): ?string
    {
        return $this->unrestrictedLevel;
    }
}

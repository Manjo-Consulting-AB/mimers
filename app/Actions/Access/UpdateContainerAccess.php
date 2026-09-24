<?php

namespace App\Actions\Access;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ändrar en levande åtkomst: `level` och `expires_at` — se issue 55a
 * § Beslut 8, fjärde utbrytningen.
 *
 * Bryts ut ur App\Http\Controllers\Api\ContainerAccessController::update()
 * med kroppen oförändrad. Webben har samma skrivning, och § Beslut 9:s
 * uppdelning gäller SVARET — JSON kontra en mening — inte VILLKORET: "en
 * död rad ändras inte" är en domäninvariant, och två formuleringar av den i
 * två kontrollrar är precis den sortens andra sanning § Beslut 8 finns till
 * för att förhindra. 55b och issue 57 lägger till fler skrivytor mot samma
 * rader.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, precis
 * som i dag — `ContainerPolicy::manageAccess()` är regel 1 MED
 * `read_only`-kontroll, till skillnad från `revokeAccess()`, för att ändra
 * en åtkomst är att hantera åtkomster ([[Konton och åtkomst]]
 * § Behörighetsregler regel 3 och 4). Att pröva den här hade varit att
 * pröva den två gånger; se issue 54 § Beslut 3, samma uppdelning som
 * App\Actions\Container\CreateContainer gör för containertaket.
 *
 * **Kroppen rör bara de två fälten.** `item_id`, `grantee_type`,
 * `grantee_id` och `kind` är `prohibited` i den delade
 * UpdateContainerAccessRequest och är inte `#[Fillable]` på
 * App\Models\ContainerAccess — att flytta en grant är att avsluta en
 * relation och börja en ny, och historiken ska visa det.
 *
 * Sedan issue 111 skrivs `access.updated` i SAMMA transaktion som raden
 * ([[ADR-0043 Tre loggar]] § Händelseloggen), med samma `meta`-form som
 * App\Actions\Access\RevokeContainerAccess — `grantee_type`, `grantee`,
 * `item`, `level` och `kind`, och **aldrig en e-postadress** (issue 40
 * § Beslut 10). `meta.changed` och `meta.values` läggs till ovanpå: nivån är
 * en värdelista och utgången ett datum, så de bär gamla och nya värdet.
 * **En ändring som inte ändrar något skriver ingen rad.**
 */
class UpdateContainerAccess
{
    /**
     * Fälten som får bära gamla och nya värdet i `meta.values`.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = ['level', 'expires_at'];

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Kastar `ApiException` (`container_access.revoked`, 422) när raden är
     * död, och fyller annars `level`/`expires_at` och sparar.
     *
     * Undantaget bubblar upp till anroparen, som formulerar sitt eget svar:
     * `/api` låter det bli `{"error":{"code":…}}` ur `Responsable`, webben
     * fångar det och översätter med App\Support\Frontend\ApiErrorTranslator
     * till en mening i formuläret — samma kod, samma rad, två svar.
     *
     * Att höja nivån på en återkallad eller utgången rad är antingen ett
     * misstag eller en väg runt återkallandet, och båda ska nekas.
     *
     * @param  Container  $container  Containern raden hör till, redan
     *                                upplöst av route-modellbindningen.
     *                                Bärs för att de fyra Actionerna anropas
     *                                likformigt — container och rad först —
     *                                och för att loggraden ska slippa en
     *                                lazy-load-fråga.
     * @param  ContainerAccess  $access  Raden ur route-modellbindningen.
     * @param  array<string, mixed>  $attributes  `level` och/eller
     *                                            `expires_at`, redan
     *                                            validerade av
     *                                            UpdateContainerAccessRequest.
     *                                            Ett `expires_at: null` rensar
     *                                            utgången och är tillåtet på
     *                                            `/api`; webbytan skickar
     *                                            aldrig det (§ Beslut 3 i
     *                                            arkitektsvaret).
     * @param  User  $actor  Den som ändrar; blir `user_id` på loggraden.
     *
     * @throws ApiException
     */
    public function handle(Container $container, ContainerAccess $access, array $attributes, User $actor): ContainerAccess
    {
        if ($access->revoked_at !== null || ($access->expires_at !== null && $access->expires_at->isPast())) {
            throw ApiException::make('container_access.revoked', ['access' => $access->ulid], 422);
        }

        $access->fill($attributes);

        // Läsningen sker FÖRE `save()`: `getDirty()` är skillnaden mot
        // databasen, och efter en sparad rad är den tom.
        $changes = $this->changedFor($access);

        if ($changes === null) {
            return $access;
        }

        DB::transaction(function () use ($container, $access, $actor, $changes): void {
            $access->save();

            // `withTrashed()`: en grant på ett sedan länge mjukraderat item
            // ska loggas med sitt item, inte som `null` — samma skäl som
            // App\Actions\Access\RevokeContainerAccess använder det.
            $item = $access->item_id === null
                ? null
                : Item::withTrashed()->whereKey($access->item_id)->first();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_ACCESS_UPDATED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'container_access',
                subjectUlid: $access->ulid,
                meta: array_merge([
                    'grantee_type' => $access->grantee_type,
                    'grantee' => $this->granteeUlid($access),
                    'item' => $item?->ulid,
                    'level' => $access->level,
                    'kind' => $access->kind,
                ], $changes),
            );
        });

        return $access;
    }

    /**
     * `meta.changed` och `meta.values` för de fält som ändrades, eller null
     * när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|int|bool|null, to: string|int|bool|null}>}|null
     */
    private function changedFor(ContainerAccess $access): ?array
    {
        $dirty = $access->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $this->valueFor($access, $column, original: true),
                    'to' => $this->valueFor($access, $column, original: false),
                ];
            }
        }

        return ['changed' => $changed, 'values' => $values];
    }

    /**
     * Värdet med sin cast. En utgång bär en tid på dygnet och serialiseras
     * därför som ISO 8601 — till skillnad från schemats datum, som är
     * datum utan tidszon (issue 13a § Beslut 5).
     */
    private function valueFor(ContainerAccess $access, string $column, bool $original): string|int|bool|null
    {
        $value = $original
            ? $access->getOriginal($column)
            : $access->getAttribute($column);

        return $value instanceof DateTimeInterface
            ? $value->format(DateTimeInterface::ATOM)
            : $value;
    }

    /**
     * Mottagarens ULID. En platt fråga och ingen relation: `ContainerAccess`
     * har medvetet ingen `grantee()`-relation, se modellens docblock — samma
     * teknik som App\Actions\Access\RevokeContainerAccess.
     */
    private function granteeUlid(ContainerAccess $access): ?string
    {
        return $access->grantee_type === 'user'
            ? User::query()->whereKey($access->grantee_id)->value('ulid')
            : Account::query()->whereKey($access->grantee_id)->value('ulid');
    }
}

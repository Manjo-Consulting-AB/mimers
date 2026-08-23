# Datamodell – översikt

Hur delarna hänger ihop, plus konventioner som gäller **alla** tabeller. Läs den här först, sedan bara den domänfil din uppgift rör.

Tillbaka till [[00 Index]].

## Entiteterna i ett svep

```
account (personligt eller organisation)
  ├── user (medlemmar i kontot)
  ├── subscription → plan
  └── container (ägs av exakt ett konto)
        ├── container_access (medlem / förvaltad org / gäst)
        ├── category (hierarki, per container)
        ├── tag (platt, per container)
        └── item
              ├── item_tag
              ├── item_link (parent/child/sibling)
              ├── attachment → stored_file (dedup via hash)
              ├── cost_entry (kostnadsrader)
              ├── loan (utlåning)
              └── schedule
                    └── schedule_occurrence (öppen + historik)
                          └── occurrence_dependency
```

Vid sidan om, kopplat till konto och användare:

```
notification → notification_delivery (per kanal)
webhook_endpoint → webhook_delivery
usage_counter (förbrukat utrymme per konto)
audit_log
```

## Var bor vad

| Domän | Fil |
|---|---|
| account, user, container, container_access, invitation, ownership_transfer | [[Konton och åtkomst]] |
| item, category, tag, item_link, loan, cost_entry | [[Items och organisation]] |
| schedule, schedule_occurrence, occurrence_dependency | [[Scheman och uppgifter]] |
| stored_file, attachment | [[Filer och lagring]] |
| notification, notification_delivery, webhook_endpoint, subscription_preference | [[Notiser]] |
| plan, subscription, usage_counter, entitlement | [[Planer och kvoter]] |

## Konventioner för alla tabeller

**Nycklar.** `BIGINT UNSIGNED AUTO_INCREMENT` som primärnyckel. Varje tabell som exponeras i API:et har dessutom en `ulid CHAR(26)` med unikt index — det är den identifierare som syns utåt. Löpnummer i API:et läcker hur många kunder du har och låter någon räkna uppåt.

**Tidsstämplar.** `created_at`, `updated_at` på allt. `TIMESTAMP`, lagras i UTC, konverteras aldrig i databasen.

**Soft delete.** `deleted_at TIMESTAMP NULL` på allt användarskapat innehåll — container, item, attachment, category, tag, schedule. Detta är det enskilt viktigaste dataskyddet i systemet; se [[ADR-0008 Soft delete och papperskorg]]. Alla index som används för listning måste inkludera `deleted_at`.

**Teckenuppsättning.** `utf8mb4` med `utf8mb4_unicode_ci` genomgående. Emoji i itemnamn ska fungera.

**Främmande nycklar.** Alltid deklarerade. `ON DELETE RESTRICT` som standard — hård radering ska vara ett medvetet beslut, inte en kaskad. Undantag anges explicit i respektive fil.

**Pengar.** Aldrig flyttal. `BIGINT` i minsta valutaenhet plus `currency CHAR(3)`.

**Byte.** Alltid `BIGINT UNSIGNED`. `INT` tar slut vid 2 GB.

**Uppräkningar.** `VARCHAR` med CHECK-villkor, inte MySQL `ENUM`. ENUM kräver schemaändring för varje nytt värde.

## Sådant som är lätt att göra fel

- **Kvoter räknas på uppladdande konto**, inte på containerns ägare. Annars fyller varvet sin kunds gratiskvot. Se [[Planer och kvoter]].
- **Innehållshashen beräknas alltid på servern.** Tar du emot en hash från klienten kan någon lägga beslag på en annan användares fil genom att gissa den. Se [[ADR-0006 Innehållsadresserad lagring]].
- **En container har exakt en ägare, och ägaren är ett konto — aldrig en användare.** Se [[ADR-0002 Konto äger container]].
- **Scheman genererar aldrig serier i förväg.** Endast öppen förekomst plus historik. Se [[Scheman och uppgifter]].

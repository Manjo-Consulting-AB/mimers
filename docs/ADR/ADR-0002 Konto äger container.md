# ADR-0002 Konto äger container

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Systemet ska fungera lika bra för båtar, husvagnar, stugor och hus, eftersom marknaden i förlängningen kan vara en annan än segelbåtar. Samtidigt finns tre B2B-segment — nybyggnadsvarv, mäklare och charterbolag — där en enda kund behöver hundratals objekt och flera anställda med olika behörighet.

Ordet "container" är dubbeltydigt: det kan betyda det ägda objektet eller en förvaringslåda i båten. Här betyder det **alltid det ägda objektet**. Att en användare kallar en låda för container är hennes sak och påverkar inte systemet.

## Beslut

Containern ägs av exakt **ett konto**. Ett konto är antingen personligt eller en organisation — **samma tabell**. Ett privatkonto är bara ett konto med en enda medlem. Planer, kvoter och fakturering hänger på kontot, aldrig på användaren.

Containers kan dessutom stämplas ut från en **mall** via `template_source_id`.

## Motivering

Hade containern ägts av en användare skulle varje B2B-funktion kräva en refaktorering genom hela behörighetsmodellen. Med konto som ägarenhet blir varvsstödet en ny rad i plantabellen istället för ny kod.

Namngivningen måste dessutom abstraheras redan i datamodellen. Att döpa om `boat` till något generiskt senare är en migration som rör allt.

Mallar kommer ur två konkreta behov: ett varv som bygger fyrtio likadana båtar, och ett charterbolag med trettio identiska Bavaria 46, vill inte fylla i samma grundpärm om och om igen.

## Konsekvenser

- `container.account_id`, aldrig `user_id`.
- Ägarbyte blir en flytt mellan konton och fungerar likadant för nybyggnadsvarv → kund, mäklare → köpare och privat försäljning. Se [[Konton och åtkomst]].
- Vid ägarbyte måste mottagarens plan kontrolleras, annars ärver ett gratiskonto en pärm på åtta gigabyte.
- `container.kind` finns för presentation och mallval, men systemet beter sig aldrig olika beroende på värdet.

## Alternativ

**Container ägs av en användare, organisationer som specialfall.** Enklare i början. Valdes bort — specialfallet växer in i varje behörighetskontroll.

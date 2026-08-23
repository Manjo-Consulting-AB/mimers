# ADR-0012 Sök

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

När taggar och kategorier är ett blankt papper ([[ADR-0004 Fria taggar och kategorier]]) finns ingen annan väg till innehållet än sök och filtrering. Det gör dem till produkten, inte till en detalj.

Delad hosting utesluter samtidigt långlivade processer, vilket är vad Meilisearch och Typesense kräver.

## Beslut

Bygg mot **Laravel Scout** med **databasdrivern** i MVP. Byt till Meilisearch när det finns en VPS.

Lägg huvuddelen av arbetet på **filtrering och index**, inte på fritextsöket.

## Motivering

Scout gör bytet till en konfigurationsändring istället för en refaktorering — appkoden rör inte drivrutinen.

MariaDB FULLTEXT räcker längre än man tror för ett innehåll som till stor del består av produktnamn, tillverkare, modellbeteckningar och serienummer. Det som saknas är svensk stemming: "batteri" hittar inte "batterier". Det märks, men inte tillräckligt för att motivera en sökmotor som inte kan köras på plattformen.

Filtreringen är det användaren faktiskt gör oftast, och den är vanlig SQL med rätt index.

## Konsekvenser

- FULLTEXT-index på `item(name, description, manufacturer, model, serial_number)`.
- Index på `item_tag(tag_id, item_id)` — filtrering på tagg är den vanligaste operationen i hela systemet.
- Kategorifiltrering måste inkludera underkategorier, vilket kräver rekursiv upplösning i applikationslagret.
- Sökresultat måste alltid begränsas till containers användaren har åtkomst till. Ett sökindex som läcker mellan konton är en allvarlig incident.
- Alla listningsindex inkluderar `deleted_at`, se [[ADR-0008 Soft delete och papperskorg]].
- OCR-sökning i PDF:er ligger utanför MVP — den kostar pengar per sida och hör till Pro.

## Alternativ

**Meilisearch direkt.** Betydligt bättre relevans och stemming. Valdes bort — kräver persistent process, vilket [[ADR-0001 Stack]] medvetet undviker på delad hosting.

**Enbart `LIKE`-sökning.** Valdes bort — blir oanvändbart så fort en container har några hundra items.

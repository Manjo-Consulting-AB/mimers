# ADR-0051 Senast öppnade filer

**Status:** Antagen 2026-09-30 · Bygger vidare på [[ADR-0049 Nyligen besökta]], [[ADR-0019 Filleverans]] och [[ADR-0045 Radering av konto och person]] · [[ADR-index]]

Tonys beslut 2026-09-30, vid genomgången av `docs/Design/dokument.png` och planeringen av [[M24 Desktopdesignen]].

## Kontext

Dokumentfliken i bilden har en rad *Senast öppnade*: filerna man senast öppnade, med tidpunkt. Ingenting i datamodellen svarar på frågan. Händelseloggen i [[ADR-0043 Tre loggar]] loggar ändringar, inte visningar.

Förslaget var först en tidsstämpel `last_opened_at` på `attachment`. Då delar alla i containern samma värde. En gäst hade sett när ägaren senast öppnade ett kvitto, och bara den senaste öppningen hade funnits kvar. Det är samma läcka som lagringsstapeln i [[ADR-0050 Desktopdesignen]] § 15 undviker.

## Beslut

**Öppningarna lagras per person, som *Nyligen besökta*.** En tabell `attachment_open`:

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | FK → user | |
| attachment_id | FK → attachment | |
| opened_at | TIMESTAMP | UTC |
| created_at, updated_at | | |

Unik nyckel `(user_id, attachment_id)` och index `(user_id, opened_at)`. Ingen `ulid` och ingen `deleted_at`, av samma skäl som för `recent_visit` i [[ADR-0049 Nyligen besökta]].

**En öppning skrivs i `files.download`, efter behörighetsgrinden**, som en upsert på `(user_id, attachment_id)`. Det gäller både nedladdning och förhandsvisning, eftersom båda går genom den rutten. `files.deliver` på filoriginet skriver ingenting. Den bär en signerad URL och vet inte vem som frågar ([[ADR-0019 Filleverans]]). Miniatyrer räknas inte som öppningar.

**Varje person har högst 50 rader.** De äldsta utöver 50 raderas i samma förfrågan. Det behövs inget schemalagt jobb.

**Listan filtreras när den läses.** Läsningen går genom `ResolveItemScope`. En fil man förlorat åtkomsten till, eller som ligger i papperskorgen, syns inte, och ingen räknare avslöjar att den finns.

**Raderna är personuppgifter.**
- **Personradering:** raderna raderas med personen ([[ADR-0045 Radering av konto och person]]).
- **Bilaga, item och container:** när en bilaga rensas raderas dess rader. Det gäller också när den rensas genom att itemet eller containern rensas.
- **Export:** exporten tar inte med raderna.
- **Registerförteckningen** får en rad: syfte bekvämlighet, gallring de 50 senaste, rättslig grund berättigat intresse.

**Städningen av lagrade filer ingår inte.** Den gäller den lagrade filen över alla användare, inte en persons öppningar. Den kan läsa samma tabell senare, men det är ett eget beslut.

## Motivering

En tidsstämpel på filen hade varit en kolumn i stället för en tabell, men den hade visat andras beteende och glömt allt utom den senaste öppningen. Per person syns bara det man själv gjort, och det är vad raden i bilden säger.

Taket på 50 är högre än de 20 i *Nyligen besökta*, eftersom en fil öppnas oftare än ett item besöks och raden ska räcka till fem kort även efter att filtret tagit bort några.

## Konsekvenser

- Byggs som [[M24 Desktopdesignen]] § 177. **`risk_class: elevated`**, eftersom skrivningen sitter i filleveransen.
- [[Filer och lagring]] får avsnittet `attachment_open` och [[Registerförteckning]] en rad när § 177 byggs.
- En skrivning per öppning. Den är en indexerad upsert och en begränsad DELETE, och den räknas med i frågeräkningsproven.

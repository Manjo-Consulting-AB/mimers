## Issue

<!-- Vilken issue i docs/Backlog.md? T.ex. "Issue 4 · Autentisering med lösenord" -->

<!-- Nyckelordet måste vara engelskt — GitHub stänger inte på "Stänger". -->
Closes #

## Vad ändringen gör

<!-- Två–tre meningar. Vad gör koden nu som den inte gjorde förut? -->

## Läslista

<!--
Lista de dokument du faktiskt läst för att lösa uppgiften — inte de som stod i issuen,
utan de du öppnade. Läste du något utanför läslistan, skriv varför.
Se AGENTS.md.
-->

- [ ]
- [ ]

## Acceptanskriterier

<!--
Kopiera varje "Klart när"-punkt från issuen och peka ut testet som bevisar den.
En PR utan test per kriterium mergas inte.
-->

| Klart när | Test |
|---|---|
|  |  |

## Omfångsrutan

- [ ] Alla ändrade filer ligger inom issuens `In scope` — eller är deklarerade nedan
- [ ] Inget under `Out of scope` är rört
- [ ] Ställning som ett `install`-kommando genererade men issuen inte bad om är borttagen

<!--
Står issuen i omfångsläget `spårad` och bär en fil utanför `In scope` en av dess
"Klart när"-punkter: deklarera filen här och bygg vidare. Markören och kodblocket
läses maskinellt av .github/scripts/omfangsruta.py — skriv dem exakt så, en sökväg
per rad, och motiveringen som prosa under blocket. Lämna avsnittet tomt annars.

Står issuen i läget `fast` vidgar en deklaration ingenting: skriv i stället vilken
fil det gäller och varför under `## Frågor och antaganden`, och vänta på svar.
`Out of scope` gäller i båda lägena och går aldrig att deklarera sig förbi.

Utanför rutan:
```
app/Http/Controllers/ItemController.php
```
Rutten bakom skärmen bärs av `ItemController::index()`; utan den går "Klart när"-punkten om X inte att uppfylla.
-->

## Kontroller

- [ ] `vendor/bin/pint --test` grön
- [ ] `vendor/bin/phpstan analyse` grön
- [ ] `php artisan test` grön
- [ ] `npm run build` går igenom
- [ ] Inga nya composer- eller npm-paket — eller: godkända av Tony, se nedan
- [ ] Migrationer är additiva (expand/contract), inga destruktiva steg i samma release
- [ ] Nya tabeller följer konventionerna i AGENTS.md (ULID, soft delete, UTC, utf8mb4, inga ENUM)
- [ ] Nya felsvar använder maskinläsbara koder, ingen färdig mening i API:et

## Nya beroenden

<!-- Lämna tomt om inga. Annars: paket, varför, och vad alternativet var. Kräver Tonys godkännande. -->

Inga.

## Frågor och antaganden

<!--
Hittade du inte svaret i läslistan? Skriv frågan här istället för att gissa i koden.
Antaganden du ändå tvingats göra listas explicit så att de går att granska.
-->

Inga.

## Processnotering

<!--
En rad: vad kostade mer än det borde? Fel axel, för tunn läslista, otydlig
omfångsruta, session som svällde, test som var svårt att skriva.
"Inget" är ett giltigt och vanligt svar - men skriv det aktivt, hoppa inte
över fältet. Det här är det enda som överlever sessionen; det läses vid
milstolpsretro och landar i docs/Process/Lärdomar.md.
-->

Inget.

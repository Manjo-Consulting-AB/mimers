# ADR-0098 Kontoexporten

**Status:** Antagen 2026-11-25 · Bygger vidare på [[ADR-0091 PDF-pärmen]], [[ADR-0095 Integritetsbegäranden]] och [[ADR-0086 Pro, Pro+ och grundarpris]] · [[ADR-index]]

Tonys beslut 2026-11-25, ur frågan *Ska Mimers ha en kontoexport?* i [[Tankar]].

## Kontext

- En export gäller i dag en container ([[ADR-0091 PDF-pärmen]] och issue 41).
- Meningen *a complete copy of your account* ströks ur #1617 eftersom ingen sådan export fanns.
- [[ADR-0095 Integritetsbegäranden]] § 9 säger att containerexporten inte är ett fullständigt svar på art. 15 eller 20.

## Beslut

*Tony 2026-11-25:* Mimers får en riktig kontoexport, en fil med alla containrar, profilen, inställningarna och loggarna som rör personen. Utformat i issue 506 och 507 i [[M93 Kontoexporten]].

1. **Filen.** En ZIP innehåller `index.html` och `account.json`, och under `containers/<slug>-<ulid>/` samma tre delar som containerexporten.
2. **Containrarna** är de som kontot äger. Containrar som delats med personen listas i `shared_with_me` utan innehåll.
3. **`account.json`** byggs av `CollectPersonData`, samma källa som `privacy:collect`. Den rättsliga spärren följer därför samma regel: en tystnadsbelagd spärr nämns aldrig.
4. **Tabellen.** `export` bär även kontoexporten, med `kind = account`, `account_id` satt och `container_id` som `NULL` ([[Filer och lagring]] § export).
5. **Beställning.** På `/settings/profile` och via `/api/account/exports`.
6. **Begränsning.** Högst en export pågår åt gången, och högst tre beställs per dygn.
7. **Säkerhetsloggen.** Varje beställning skriver `account.export_requested`.
8. **Notis.** `account_export.ready` skickas när exporten är klar.
9. **Gallring.** Filen raderas 24 timmar efter att den blivit klar (`files.account_export_retention_hours`, Tony 2026-11-25), och gallringen körs varje timme. Containerexporten behåller sju dagar.
10. **Pris.** Exporten är fri på alla nivåer.

## Motivering

Det ska gå att ta med sig allt. Det är löftet om ingen inlåsning ([[ADR-0086 Pro, Pro+ och grundarpris]]), och det besvarar art. 20 utan ett ärende.

## Alternativ som valts bort

- **Containerexporten och pärmen räcker.** Tony valde bort det: personens profil, inställningar och loggar hade saknats, och art. 20 hade krävt ett ärende.
- **En knapp som startar en containerexport per container.** Tony valde bort det: den ger ingen fil med profilen och loggarna, och personen får en ZIP per container att hålla ordning på.

## Konsekvenser

- Issue 506–508 i [[M93 Kontoexporten]].
- En kontoexport kan bli lika stor som kontots lagring. Den räknas inte mot kvoten, och därför ligger den bara i 24 timmar: filen är personens hela konto i klartext och tar diskutrymme. Diskutrymmet ska ändå tas med i planen för flytten till egen VPS.
- [[ADR-0095 Integritetsbegäranden]] § 9 får ett tillägg: kontoexporten kan antecknas som `self_service`.

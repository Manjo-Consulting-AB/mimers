# ADR-0081 All lagrad data räknas mot kvoten

**Status:** Antagen 2026-11-15 · Ändrar steg 4 i [[ADR-0009 Kvoter och livscykel]] · Vidgar [[ADR-0045 Radering av konto och person]] § Beslut 1 · Kompletterar [[ADR-0017 Missbruksvektorer]] § Mätningen · [[ADR-index]]

Tonys beslut 2026-11-15. Ersätter tanken *"Inget tak på antal items"* i [[Tankar]].

## Kontext

Kvoten räknade bara filer. Text räknades inte, eftersom metadatan ansågs mikroskopisk ([[ADR-0009 Kvoter och livscykel]] § Motivering). Textfälten har dessutom ingen längdgräns: valideringen är `'string'` utan `max`, och kolumnerna är `TEXT`. Ett skript mot API:et kunde därför fylla databasen och backuperna utan att kontot betalade något. Ungefär 1000 textitems tar 2–5 MB, så 100 miljoner blir 100–500 GB. Kostnaden är liten för en vanlig användare och obegränsad för ett skript.

En maxlängd på textfälten valdes bort, eftersom användare kan vilja spara långa anteckningar.

## Beslut

### 1. Kvoten mäter filer och text tillsammans

`usage_counter` får kolumnen `metadata_bytes` bredvid `storage_bytes`. Kvoten prövas mot **summan** av de två, och planens gräns `storage_bytes` gäller summan. Kolumnerna hålls isär så att lagringsvyn kan visa vad som är filer och vad som är text, och så att avstämningen kan rätta dem var för sig.

### 2. Så räknas en rad

En rad räknas som antalet byte (UTF-8) i radens fritextkolumner plus ett **fast påslag på 256 byte per rad**. Påslaget står för id, nycklar, index och datum, och gör att miljoner tomma rader inte är gratis.

De räknade tabellerna är `container`, `category`, `tag`, `item`, `schedule`, `cost_entry` och `loan`. `schedule_occurrence` räknar bara `completion_note`, utan påslag, eftersom raderna skapas av systemet och inte av användaren.

Systemets egna tabeller räknas inte: notiser, audit-logg, säkerhetslogg, besök, favoriter och liknande. Kopplingsrader utan text räknas inte heller (`item_tag`, `item_link`, beroenden). Dem skyddar skrivbegränsningen (beslut 7).

### 3. Den som skapar raden betalar

Som för bilagor betalar det konto som skapar raden, genom `billed_account_id`. Undantaget är containerraden, som ägaren betalar för (`container.account_id`).

Slängda rader räknas tills de raderas för gott, som bilagor sedan [[ADR-0009 Kvoter och livscykel]] § Uppföljning 2026-11-11.

### 4. När betalaren försvinner tar containerns ägare över

Det gäller både bilagor och räknade rader, och i tre fall:

- **kontoradering**, vilket redan är regeln för bilagor ([[ADR-0045 Radering av konto och person]] § Beslut 1),
- **nedgradering** (beslut 6a),
- när ett konto **förlorar sin sista giltiga åtkomst** till containern, för att den återkallats eller gått ut.

`billed_account_id` skrivs om till containerns ägarkonto, och bytena flyttar mellan räknarna i samma transaktion, utan kvotprövning. Följden är densamma som idag: hamnar ägaren över gränsen nekas nya skrivningar som växer, men ingen nedgradering startar. Innehållet raderas aldrig för att en medlem försvann.

Ägaren kan bara få sin kvot fylld av innehåll från personer hen själv bjudit in, och kan ta bort det.

### 5. Kvotprövning vid skrivning

Att skapa en räknad rad, eller ändra en så att den växer, nekas med `quota.storage_exceeded` om summan då går över gränsen. Att krympa, slänga eller radera nekas aldrig.

### 6. Nedgraderingens steg 4

Steg 4 i [[ADR-0009 Kvoter och livscykel]] ändras.

- **(a) Andra containrar.** Innehåll som kontot betalar för i **andra** containrar raderas inte. Det flyttas till respektive containers ägare enligt beslut 4. Ägarna får en notis redan vid steg 1, så att de hinner städa.
- **(b) Egna containrar.** I kontots **egna** containrar tas innehållet bort i den här ordningen, tills kontot ligger på eller under gränsen:
  1. slängda bilagor, äldst slängd först,
  2. levande bilagor, nyast först,
  3. slängda items, äldst slängd först,
  4. levande items, nyast först.

  Allt raderas för gott, som sedan [[ADR-0009 Kvoter och livscykel]] § Uppföljning 2026-11-14.

Items raderas alltså sist och bara när filerna inte räcker. En vanlig användare har inte text nog att hamna där.

### 7. Skrivbegränsning per användare

Varje skrivande anrop, alltså alla metoder utom `GET`, `HEAD` och `OPTIONS`, går genom en begränsare per inloggad användare, i både webben och API:et. Gränsen är konfigurerbar, med förvalet 120 per minut. Uppladdningarna behåller sin egen begränsare. På `/api` blir svaret `429` med `rate_limit.writes_exceeded`.

Begränsaren stänger skriptvektorn oberoende av kvoten.

### 8. Mätningen

[[ADR-0017 Missbruksvektorer]] § Mätningen får två nya tal: text per gratiskonto (`metadata_bytes`), och antal items per gratiskonto, slängda medräknade. Det följer linjen "detektera och prissätt".

Ett tak på antal items per plan läggs inte nu. Det prövas om mätningen visar ett behov.

## Alternativ som valts bort

**Maxlängd på textfälten.** Begränsar legitima anteckningar.

**Ett tak på antal items per plan.** Inget mätvärde visar behovet än.

**En schablon per item mot lagringskvoten.** Räknar fel åt båda hållen: för mycket för ett tomt item, för lite för en lång anteckning.

**Att containerns ägare betalar för allt.** En medlem kan då fylla ägarens kvot medan hen är aktiv.

**Att innehållet raderas när betalaren försvinner.** En medlems nedgradering skulle radera i någon annans container. Det är dagens beteende för bilagor, och det ska bort.

## Konsekvenser

- Varje räknad tabell får `billed_account_id` (FK `account`), utom `container`. För `schedule_occurrence` är kolumnen `NULL` tills en anteckning skrivs. Se [[Items och organisation]] och [[Scheman och uppgifter]].
- Räknaren uppdateras transaktionellt vid varje skrivning som ändrar en räknad rads storlek, och det nattliga avstämningsjobbet stämmer av `metadata_bytes` för sig. Se [[Planer och kvoter]] § usage_counter.
- Kontrollpunkterna i [[Planer och kvoter]] får skapande och växande ändring av räknade rader.
- Att förlora sista åtkomsten blir en ny händelse som flyttar betalningsansvar, bredvid kontoradering och ägarbyte.
- Lagringsvyn kan visa filer och text var för sig.
- Felkoden `rate_limit.writes_exceeded` är ny i API:et.
- Notisen till ägarna vid steg 1 (beslut 6a) är en ny notistyp. Namnet bestäms i kodarbetet.

# ADR-0078 Valutor ur ISO 4217

**Status:** Antagen 2026-11-14 · Skärper [[ADR-0016 Kostnadsregistrering]] och [[ADR-0037 Valutans arv]] · [[ADR-index]]

Tonys beslut 2026-11-14 om vilka valutor systemet godtar och hur användaren väljer mellan dem. Kodarbetet ligger i issue 1252 (rapporten), 1253 (ISO-valideringen och sökrutan) och 1254 (containerns urval).

## Kontext

Servern godtar vilka tre bokstäver som helst som valuta (`alpha|size:3`) på kostnadsraden, kontot och containern. [[ADR-0016 Kostnadsregistrering]] låter summeringen ske per valuta, och framtida statistik gör det också. Det förutsätter riktiga koder: `SEK` och `SKE` blir två olika grupper, och ingen märker det förrän siffrorna är fel.

Det omvända problemet finns i formuläret. En lista över alla världens valutor betyder att man bläddrar bland ett hundratal koder man aldrig använder för att komma åt den ena man alltid väljer. Den som äger en båt i euro och ett hus i kronor behöver två valutor, inte 180.

## Beslut

**1. Bara aktiva ISO 4217-koder.** Servern godtar endast koder ur en lista över aktiva ISO 4217-valutor. Historiska koder som `DEM` och `FRF` ingår inte. Listan ligger på ett ställe i koden, och namnen kommer från ICU (`intl`) på användarens språk. Regeln gäller `cost_entry.currency`, `account.currency` och `container.currency`.

**2. Sökrutan.** Valutafälten slår upp medan användaren skriver och matchar både kod och namn: *E* och *EU* ger `EUR`, *kron* ger `SEK`, `NOK` och `DKK`. Bara ett värde ur listan kan väljas.

**3. Befintliga data rättas inte i tysthet.** Innan valideringen skärps rapporterar ett kommando de koder i databasen som inte finns i listan. Vad som görs med dem beslutas när rapporten finns. Raden är sanningen ([[ADR-0037 Valutans arv]]), så en rad med en ogiltig kod står kvar och visas, men en ändring av den kräver en giltig kod.

**4. Tillåtna valutor per container.** Under containerns inställningar väljer ägaren vilka valutor som får användas vid registrering av kostnader i containern.

- **Ingen markering betyder alla.** Ett tomt urval är inte "inga".
- **Containerns standardvaluta ingår alltid**, utan att stå i urvalet.
- **Urvalet gäller nya rader och ändrade valutor.** En befintlig rad i en valuta som tagits bort ur urvalet står kvar och kan redigeras på andra fält.
- **Servern upprätthåller regeln.** Felet är 422 `currency.not_allowed`.

Urvalet lagras i den nya tabellen `container_allowed_currency`, se [[Items och organisation]] och [[Konton och åtkomst]].

**5. Formuläret anpassar sig.** Med ett urval på högst sex valutor är fältet en enkel väljare. Annars är det sökrutan.

## Alternativ som valts bort

- **Urvalet bara i formuläret.** API:et skulle kunna kringgå det, och då är urvalet en önskan och inte en regel.
- **Att rätta ogiltiga koder automatiskt.** Historiken skulle ändras i tysthet, vilket är precis vad [[ADR-0037 Valutans arv]] vill undvika.
- **Urvalet på kontot.** Containrarna har olika behov, till exempel en båt i euro och ett hus i kronor.

## Konsekvenser

- Tre kodissuer: rapporten (1252), ISO-validering och sökruta (1253) och containerns urval (1254). Rapporten körs före valideringen skärps.
- `cost_entry`-raden ändras inte av den här ADR:n; bara vad som får skrivas in i `currency` gör det.
- En ny tabell, `container_allowed_currency`, som rensas med containern och följer med i exporten.
- Ett byte av containerns standardvaluta ändrar inget i urvalet, eftersom standardvalutan alltid ingår.

# ADR-0037 Valutans arv

**Status:** Antagen 2026-09-17 · Kompletterar [[ADR-0016 Kostnadsregistrering]] · [[ADR-index]]

Fattat vid genomgången av den första omgången mockuper. [[ADR-0016 Kostnadsregistrering]] gjorde valutan obligatorisk på kostnadsraden men sa aldrig varifrån värdet kommer. Det här är det svaret.

## Kontext

`cost_entry.currency` är `CHAR(3)` och obligatorisk. [[ADR-0016 Kostnadsregistrering]] slår fast att *"summering sker per valuta"* och att ingen omräkning görs i MVP.

Det fungerar, men det lämnar en fråga obesvarad: **vad står i fältet när formuläret öppnas?** I dag finns inget svar, vilket betyder att varje kostnadsregistrering ställer användaren en fråga hon nästan alltid besvarar likadant. Den som lagt in fyrtio kostnader på sin båt har valt SEK fyrtio gånger.

Mockuparna gör frågan akut på ett andra sätt. Dashboarden visar *"28 450 kr denna månad"* summerat tvärs över alla containrar, och containervyn visar en kostnadsdonut. Båda talen förutsätter att någon vet vilken valuta de uttrycks i, och den kunskapen finns ingenstans i systemet — den finns bara utspridd på raderna.

## Beslut

**Valutan ärvs nedåt och kan skrivas över på varje nivå.**

| Nivå | Roll |
|---|---|
| **Konto** | bär valutan. Det är kontots valuta de globala summorna uttrycks i. |
| **Container** | ärver kontots och kan ange en egen. |
| **Kostnadsraden** | föreslås containerns, och användaren kan välja en annan. |

**Ett ändrat standardval gäller bara nya poster.** Byter användaren containerns valuta märks befintliga rader aldrig om. Det som står i en rad är vad som betalades, inte vad containern för närvarande föreslår.

**Valutan står kvar per rad, obligatorisk, som i dag.** [[ADR-0016 Kostnadsregistrering]] ersätts inte. Dess *"summering sker per valuta, ingen omräkning görs"* är fortfarande svaret på hur blandade valutor presenteras.

**Ingen valuta hamnar på itemet.** Itemet är stället där formuläret öppnas, inte en nivå i arvet.

**Ingen omräkning sker någonsin automatiskt.** Har användaren betalat i en annan valuta räknar hon själv, eller registrerar raden i den valuta hon betalade.

## Motivering

Arvet tar bort en fråga utan att ta bort ett svar. Användaren möter aldrig en valutaväljare hon behöver bry sig om, men raden bär ändå sanningen — och det är den kombinationen som gör beslutet billigt. `cost_entry` rörs inte alls; hela ändringen är en kolumn på `account` och en nullbar på `container`.

**Regeln om att bara nya poster påverkas är det som gör arvet ofarligt.** Utan den skulle ett byte av containerns valuta märka om historiken tyst: tre år av svenska kronor blir euro för att någon ändrade en inställning. Med valutan kvar på raden kan det inte hända, och det är det enda skälet att inte lägga valutan enbart på containern och spara en kolumn.

**Kontot är rätt hem för grundvärdet**, därför att det är den enda nivå som kan svara på vad en global summa betyder. Sitter valutan bara på containern har dashboardens totalsumma ingen ägare — den summerar tal ur olika containrar och har ingen nivå ovanför sig att hämta enheten från.

**Att raden ändå kan avvika** kostar ingenting att tillåta och löser ett verkligt fall: en reservdel köpt utomlands, en hamnavgift i euro. Alternativet — att tvinga fram omräkning vid inmatning — flyttar ett bokföringsjobb till den som minst vill göra det, och gör datan sämre, eftersom kvittot säger något annat än raden.

## Konsekvenser

- **Schemat:** en valutakolumn på `account` och en nullbar på `container`. `cost_entry` ändras inte.
- **Formuläret visar valutan men behöver inte frågas.** Fältet är förifyllt och ändringsbart, inte dolt — ett dolt fält som ändå står i datan är ett fel som upptäcks först i en rapport.
- **Presentation av blandade valutor är ett känt och medvetet uppskjutet problem.** Det blir synligt på exakt två ytor: dashboardens totalsumma och containerns kostnadsdonut. Båda har redan svaret i [[ADR-0016 Kostnadsregistrering]] — gruppera per valuta, summera inte över dem. Att bygga den grupperingen är ett designval, inte en datafråga, och hör till rapportvyn som ADR-0016 redan pekat ut som ett eget arbete.
- **Kontots valuta måste sättas vid registrering.** Det är ett fält till i ett flöde som redan är för långt, så det bör ha ett vettigt förval och kunna ändras i inställningarna efteråt — aldrig en blockerande fråga.
- **Valutan är presentation, inte beräkning.** Beloppet lagras fortfarande i minsta valutaenhet enligt husets penningkonvention, och avrundning sker först vid presentation, som [[ADR-0016 Kostnadsregistrering]] kräver.
- **Enhetssystemet rörs inte.** `unit_system` på konto och användare enligt [[ADR-0013 Språk och i18n]] är en annan sak och ärvs inte via containern.

## Alternativ

**Valutan enbart på containern, borttagen från raden.** Enklaste datamodellen och det ursprungliga förslaget. Valdes bort — ett byte av containerns valuta hade märkt om all historik tyst, och borttagningen hade dessutom krävt en migrering av en tabell som redan bär data, vilket är mer arbete än att behålla kolumnen.

**Valutan enbart på kontot, utan möjlighet att avvika.** Enklast att summera. Valdes bort — den som har ett hus i Spanien och en båt i Sverige har två valutor oavsett vad systemet tycker, och tvingad omräkning gör raden osann mot kvittot.

**Automatisk omräkning mot dagskurs.** Skulle lösa presentationsproblemet på riktigt. Valdes bort för MVP av samma skäl som i [[ADR-0016 Kostnadsregistrering]] — det kräver en kurskälla, en policy för vilken dags kurs som gäller, och ett svar på vad som händer när kursen revideras i efterhand. Tre frågor för ett problem nästan ingen användare har.

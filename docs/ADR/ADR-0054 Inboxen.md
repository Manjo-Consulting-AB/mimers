# ADR-0054 Inboxen

**Status:** Antagen 2026-10-05 · Ändrar [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 2 · Bygger på [[ADR-0053 Flytt och kopiering]] och [[ADR-0028 Åtkomst på itemnivå]] · [[ADR-index]]

Tonys beslut 2026-10-05, efter genomgången av uppgifterna på staging (v0.20.0).

## Kontext

Allt i Mimers hänger på ett item. Det är regeln som bär åtkomsten, kvoterna, lagringen, händelseloggen och raderingen. Den gör också att något som ännu saknar hemvist inte kan fångas: en uppgift eller ett kvitto måste placeras innan det kan sparas.

GTD börjar med att fånga och sorterar sedan. [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] gjorde *Inbox* till en lista som betydde *obearbetad*, men uppgiften måste ändå ha ett item. Det gav användaren ingen plats att fånga något utan att först bestämma var det hör hemma.

Att låta `item_id` vara null hade krävt ett undantag i varje policy och varje fråga som går via item, container och konto. Det är så åtkomstbuggar uppstår.

## Beslut

### 1. En dold inbox per användare

**Varje användare har en personlig inbox, som skapas första gången den behövs: en container med ett enda item, båda dolda.** Containern ägs av användarens personkonto och bär `container.inbox_user_id` (FK → user, NULL, UNIQUE). Den kolumnen är det enda som gör containern till en inbox. `kind` används inte till detta, eftersom [[ADR-0036 Containerns art]] förbjuder att `kind` styr beteende.

Den skapas i en transaktion, med det unika indexet som skydd mot dubbletter. Det behövs ingen migrering för befintliga användare.

**Användaren ser aldrig containern eller itemet**, bara *Inbox*.

### 2. Bara användaren själv når sin inbox

Ett personkonto kan ha fler medlemmar, och regel 1 i [[ADR-0028 Åtkomst på itemnivå]] ger varje medlem hela kontots containrar. **För en inbox gäller den regeln inte.** Inboxen nås av `inbox_user_id` och av ingen annan, oavsett roll i kontot.

- `Container::scopeAccessibleBy()` utesluter varje inbox. Inboxen nås bara genom sina egna ytor. Den syns därför inte i containerlistan, sidopanelen, sökningens containerfilter, dashboardens kort, kartan eller någon annan lista över containrar.
- `ContainerPolicy` och `ItemPolicy` släpper bara igenom `inbox_user_id` för en inbox, med `view`, `create`, `update` och `delete` på innehållet. En inbox kan inte delas, överlåtas, döpas om, raderas, exporteras som container eller få en bild.
- Inget nytt item kan skapas i en inbox, och inga relationer kan läggas på dess item.

Ändringen ligger i åtkomstlagret och prövas därefter.

### 3. Kvoter

**Inboxen räknas inte mot planens tak för containrar.** Gratisplanens enda container är användarens egen.

**Det som ligger i inboxen belastar personkontots lagringskvot.** Personkontot är inboxens ägarkonto, och regeln i [[ADR-0053 Flytt och kopiering]] § 3 ger det som laddas upp dit personkontot som ägare.

### 4. Vad inboxen tar emot

Uppgifter och bilagor, alltså bilder och dokument. Inga items, kostnadsrader, lån eller relationer.

### 5. Inbox är en plats, inte en GTD-lista

Det här ändrar [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 2:

- **`gtd_list` får värdena `next`, `waiting` och `someday`.** En förekomst i inboxen har `gtd_list = null`. Varje annan förekomst har ett värde. `inbox` utgår som värde.
- **Inbox är en härledd vy:** förekomster vars schema ligger på användarens inbox-item.
- **En uppgift som skapas på ett riktigt item får `next` som förval**, och användaren kan välja `waiting` eller `someday`. Den hamnar aldrig i Inbox. Det upphäver regeln i ADR-0052 § 2 att varje manuellt skapad uppgift börjar i Inbox.
- **En uppgift som skapas utan plats hamnar i inboxen** med `gtd_list = null`.
- **Regeln som lär in förvalet utgår.** `schedule.default_gtd_list` sätts till den lista som valdes när uppgiften skapades eller bearbetades, och nästa förekomst får den. Förvalet kan ändras på schemats redigeringssida som förut.

Vyerna blir:

| Vy | Villkor |
|---|---|
| *Inbox* | aktiv och på användarens inbox-item |
| *Next*, *Waiting*, *Someday* | aktiv och `gtd_list` med det värdet |
| *Active* | aktiv, var den än ligger |
| *In progress* | `status = in_progress` |
| *Calendar* | aktiv och `due_at IS NOT NULL` |
| *Done* | `status = completed` |

En uppgift kan stå i flera vyer samtidigt. *Next*, *In progress* och ett datum ger *Next*, *Active*, *In progress* och *Calendar*.

### 6. Att bearbeta inboxen

**En uppgift bearbetas** genom att användaren väljer ett item, en lista (förvalt *Next*) och, om hon vill, ett datum. I en transaktion flyttas schemat till itemet enligt [[ADR-0053 Flytt och kopiering]], och den öppna förekomsten får sin lista och sitt datum. Schemats förval sätts till listan. Uppgiften har lämnat inboxen.

**En bilaga bearbetas** genom att användaren väljer ett item. Den flyttas enligt ADR-0053, och kvoten prövas hos den nya ägaren. Nekas den ligger bilagan kvar i inboxen.

**Flera bilagor kan bearbetas på en gång** till samma item. Det sker i en transaktion med en kvotkontroll för summan: antingen flyttas alla eller ingen.

Målitemet väljs bland de items där användaren har `create`, i alla containrar hon når.

### 7. Var inboxen syns

- **Fliken *Inbox* på `/tasks`.** Containerns Tasks-flik har ingen inbox, eftersom inboxen inte hör till någon container.
- **En sida `/inbox`** i sidopanelen, med inboxens uppgifter och filer på samma ställe och med antal i panelen. Det är där bilder och dokument fångas och bearbetas.
- **Sökningen** visar inboxens bilagor och uppgifter för användaren själv, med platsen *Inbox*.

### 8. Var något fångas

En uppgift eller en fil som skapas utan sammanhang hamnar i inboxen: knappen *New task* på `/tasks` och uppladdningen på `/inbox`. Där ett sammanhang finns, på ett item eller i en container med ett valt item, går den dit och inte till inboxen.

### 9. Radering och export

- **Inboxen raderas med personen** ([[ADR-0045 Radering av konto och person]]), även när personkontot har andra medlemmar och därför står kvar. Den är personens och ingen annans.
- **Inboxen ingår inte i någon containerexport.** Om den ska ingå i personens egen dataexport avgörs när den exporten byggs.

## Öppna frågor

- **Personens dataexport.** Om inboxen ska ingå avgörs när den exporten byggs.
- **Återkommande uppgifter i inboxen.** Ingenting förbjuder dem i dag. En sådan uppgift har `gtd_list = null` tills den bearbetas. Frågan avgörs när skapandet från inboxen byggs.

## Motivering

**Regeln att allt har ett item behålls.** Åtkomsten, kvoterna, lagringen och loggen fungerar som de gör, och den enda nya regeln är vem som når en inbox. Det är ett undantag på ett ställe i stället för ett i varje fråga.

**Inbox som plats gör GTD-modellen renare.** Att något är obearbetat och att det saknar hemvist blir samma sak, och användaren behöver bara lära sig ett ord. De tre listor hon kan välja är de tre som säger något om hur hon tänker hantera uppgiften.

**Personlig och inte kontots**, eftersom en inkorg är där man fångar tankar innan de är färdiga. Den som delar sitt konto med en sambo ska inte dela sina halvfärdiga anteckningar.

## Konsekvenser

- **Migrering:** förekomster med `gtd_list = 'inbox'` får `next`. Kolumnen blir nullbar, och CHECK-villkoret blir `IN ('next','waiting','someday')`. **`schedule.default_gtd_list` kontrolleras också:** CHECK-villkoret tillåter bara `next` och `someday`, men det läggs bara på mysql, och migreringen skriver om varje rad med ett annat värde än `next`, `someday` eller null till `next` i stället för att lita på villkoret. Endast staging har sådana rader, eftersom v0.20.0 inte är släppt till produktion. **v0.20.0 släpps inte till produktion före den här ändringen.**
- **Issue 235:s regler för förvalet** (#736) och formulärets alternativ *Inbox* (#737) skrivs om.
- **Fliken *In progress*** läggs till bredvid *Active* på `/tasks` och containerns flik.
- `accessibleBy` och de två policyerna ändras, och varje yta som listar containrar får ett prov på att inboxen inte syns där. Det gäller containerlistan, sidopanelen, dashboarden, sökningen, ICS-flödet, exporten, ägarbytet och delningen. Issuerna blir `risk_class: elevated`.
- [[Konton och åtkomst]] beskriver `container.inbox_user_id` och undantaget från regel 1. [[Scheman och uppgifter]] beskriver `gtd_list` utan `inbox`.

## Alternativ

**`item_id` nullbart, med en ägare direkt på uppgiften och bilagan.** Ligger närmast GTD:s inkorg. Valdes bort, eftersom varje policy och fråga som går via item, container och konto hade behövt ett undantag.

**En inbox per konto.** Valdes bort. Alla medlemmar hade sett varandras inkorg.

**Inbox som GTD-lista, skild från platsen.** Det var ADR-0052. Valdes bort, eftersom användaren då har två begrepp för samma upplevelse.

**`kind = 'inbox'`.** Valdes bort, eftersom `kind` är ett fritt fält som aldrig får styra beteende ([[ADR-0036 Containerns art]]).

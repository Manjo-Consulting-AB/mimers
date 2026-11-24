# M87 · Integritetsbegäranden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24 ur #1595, efter Tonys svar på arkitektens frågor samma dag. Mimers har ingen plats att registrera en begäran från en registrerad, ingen frist någon håller reda på och inget bevis för hur den hanterades. Milstolpen bygger [[ADR-0095 Integritetsbegäranden]]: ärendena sköts med artisan-kommandon, utan adminsida och utan personalroll.

**Ordningen:** åtta issuer, skapade i GitHub (#1626–#1633) och sammanfattade här. GitHub-issuen är bindande. **Issue 488 är ett förslag och inte beslutat**: Tony avgör om och när den byggs. Utanför milstolpen ligger det som #1595 själv utesluter: full automatisk upptäckt av personuppgifter, automatiska rättsliga beslut och en självbetjäningsyta.

---

### 481. Ärendet och intaget: privacy:open och privacy:show

GitHub #1626. Mimers har i dag ingen plats att registrera en begäran. Efteråt öppnar den som har tillgång till servern ett ärende med `privacy:open`, läser det med `privacy:show` och får tillbaka en referens att ge den som begärt.

Tabellerna `privacy_request` och `privacy_request_event`, `config/privacy.php` och actionen `OpenPrivacyRequest`, som `/contact` ska kunna anropa när formuläret byggs. Referensen är `PR-` och åtta tecken i Crockfords base32, och fristen är mottagandet plus en kalendermånad. `account_id` och `user_id` är identifierare utan främmande nyckel, så att ärendet överlever radering. Ärendet skriver inget till `audit_log` eller `security_log`, och `privacy:show` skriver en händelse `viewed`.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1634 (ADR-0095 och tabellerna i datamodellen)

### 482. Statusarna, tilldelningen och verifieringen

GitHub #1627. Efteråt flyttar handläggaren ärendet mellan statusarna, tilldelar det, sätter prioritet och antecknar hur identiteten och ett ombuds behörighet har prövats.

Fritt mellan de öppna statusarna. Till ett avslutat läge krävs ett skäl, utom till `completed`, och mellan två avslutade lägen går det inte: öppna ärendet först. Verifieringen är en händelse med ämne, metod och resultat, och ingen kopia av en identitetshandling sparas. Kopplingen till konto och person görs här, när identiteten är prövad.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1626

### 483. Fristen, förlängningen och privacy:list

GitHub #1628. Efteråt kan fristen förlängas och räknas om, och listan visar vad som brådskar.

`privacy:extend` förlänger med högst två månader sammanlagt, med skäl och bara före den ursprungliga fristen. `privacy:deadline` räknar om fristen från en ny dag när klockan börjat om. `privacy:list` sorterar de öppna ärendena på frist och visar dagar kvar eller *overdue*, utan namn, e-postadress eller beskrivning. Ingen påminnelse: inget nattjobb, inget mejl och ingen loggrad ([[ADR-0095 Integritetsbegäranden]] § 6).

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1626

### 484. Bedömning, åtgärder och kommunikation

GitHub #1629. Efteråt dokumenteras hur begäran prövades och vad som gjordes, som händelser med värden ur listor i `config/privacy.php`.

`privacy:assess` skriver en bedömning och sätter ärendets roll. Flera bedömningar per ärende gör delvis bifall möjligt. `privacy:action` skriver vilken åtgärd som gjordes i vilket system, `privacy:communicate` skriver kommunikationen med den registrerade och `privacy:note` en fri anteckning. `retained` kräver ett skäl att behålla, `self_service` en funktion, och den rättsliga spärren godtas bara när kontot faktiskt omfattas av en.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1626

### 485. Begränsningen som markering

GitHub #1630. Efteråt kan en begränsning enligt art. 18 sättas och hävas på ärendet, och personalen ser vilka konton den gäller.

`privacy:restrict` och `privacy:unrestrict` sätter omfattning och tidpunkter och skriver händelser. Begränsningen är en driftinstruktion och ingen funktion: den gör inte kontot skrivskyddat, stoppar ingen inloggning och läses inte av någon kod ([[ADR-0095 Integritetsbegäranden]] § 8). `privacy:list --restricted` visar också avslutade ärenden, och att avsluta ett ärende häver inte begränsningen.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1627, #1628

### 486. Bilagorna på ärendet

GitHub #1631. Efteråt lägger handläggaren en fil på ärendet med `privacy:attach`: svaret som skickades, en sammanställning eller en fullmakt.

Tabellen `privacy_request_attachment` och en egen disk, `privacy`, under `storage/app/private/privacy` med filer i läge `0600`. Bilagorna hör inte till den innehållsadresserade lagringen, räknas inte mot någon kvot och levereras aldrig av en rutt. Hashen räknas på servern, och en bilaga tas bort med skäl.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated
**Beror på:** #1626

### 487. Gallringen av avslutade ärenden

GitHub #1632. Ett avslutat ärende bär namn, e-postadress, anteckningar och bilagor om en registrerad. Efteråt gallras det 36 kalendermånader efter avslutet, med händelser, bilagor och filer, varje natt av `prune-privacy-requests`.

Ett öppet ärende gallras aldrig. Den rättsliga spärren på ärendets konto går före gallringen, men en gällande begränsning gör det inte. Tiden bor i `config/privacy.php` som `retention_months`.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated
**Beror på:** #1631, #1627

### 488. Insamlingen för artikel 15 (förslag)

GitHub #1633. **Förslag, inte beslutat.** En begäran om tillgång kräver i dag att handläggaren frågar databasen tabell för tabell. Efteråt sammanställer `privacy:collect` de uppgifter i databasen som Mimers är personuppgiftsansvarig för om den person ärendet är kopplat till, som en JSON-fil som läggs på ärendet som bilaga.

Det är den första automatiska hanteraren enligt [[ADR-0095 Integritetsbegäranden]] § 12. Ingen sammanställning görs innan identiteten är prövad, och den rättsliga spärren nämns aldrig i det som lämnas ut. Grunden för det ska prövas juridiskt innan kommandot används, se [[Tankar]].

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated
**Beror på:** #1627, #1631

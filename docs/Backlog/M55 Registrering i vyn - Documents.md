# M55 · Registrering i vyn: Documents

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12. Bygger [[ADR-0074 Registrering i vyn]] § 7 för *Documents*, den sista av de tre milstolparna i ordningen *To do*, *Items*, *Documents*. Skalen kom i [[M52 Registrering i vyn]] och *Items* i [[M54 Registrering i vyn - Items]]. Uppladdningskön bryts ut ur bilagefliken (367), och containerns *Documents*-flik får ett infällbart formulär med itemväljare och kö (368). Issuerna skrevs direkt i GitHub (#1166, #1167) och är sammanfattade här.

**En öppen fråga som återstår efter M55:** ADR-0074 § 7 säger att kortkommandot `N` och plusknappens mål bestäms när alla tre flikarna är byggda. Det är de nu, men frågan är inte avgjord. Plusknappen (`CreateTarget::forContainerTab()`) leder fortfarande till itemets bilageflik på *Documents*, och `/items/create` finns kvar för *Items*. Frågan står också i [[Tankar]] § Öppet.

**Releasen:** en, när 367–368 är mergade.

---

### 367. Uppladdningskön som egen komponent

GitHub #1166. **Läget:** uppladdningskön bor inne i `ItemAttachmentSection.vue` (1 048 rader) och kan bara användas på itemets bilageflik.

**Lösningen:** kön flyttas oförändrad till `AttachmentUploadQueue.vue`: filväljaren, släppytan, felen per fil, kontovalet och sammanfattningen. Props är `containerUlid`, `itemUlid` (`null` stänger av väljaren och släppytan och ritar *Choose an item first.*), `maxUploadBytes`, `containerAccount` och `containerIsInbox`. Händelserna är `busy`, `uploaded` och `finished`. Bilagefliken ritar komponenten och ser ut som förut. `BilagekoTest` och `BilagevisningTest` pekas om till den nya filen.

**Läs:** `ItemAttachmentSection.vue` (kön, rad ~190–480 och dess markup); `BilagekoTest.php`

**Klart när:** komponenten finns och bär kön, släppytan, kontovalet, felen per fil och sammanfattningen; bilagefliken ser ut och beter sig som förut; med `itemUlid = null` är filväljaren och släppytan avstängda och *Choose an item first.* visas; `busy`, `uploaded` och `finished` skickas; proven pekar på den nya filen och sviten är grön.

**Beror på:** —

### 368. Ladda upp dokument direkt på containerns Documents-flik

GitHub #1167. **Läget:** *Add document* på containerns *Documents*-flik är en meny som leder till itemets bilageflik, där uppladdningen sker.

**Lösningen:** menyn tas bort och `InlineCreate` ritas mellan filterraden och listan, när containern har items. Överst i formuläret står en itemväljare som en `<details>`-knapp i samma form som platsknappen på *To do*; har containern ett enda item är det förvalt. Under den står `AttachmentUploadQueue` med det valda itemet. Formuläret står kvar efter uppladdningen, och stängning mitt i en uppladdning frågar först. Nya dokument markeras i 2000 ms, och döljer filtret eller sidnumreringen dem visas ett besked. `ContainerDocumentController` ger `maxUploadBytes`. Plusknappen rörs inte.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 7; `Containers/Documents.vue`; `AttachmentUploadQueue.vue`; `InlineCreate.vue`; `ContainerTaskQuickCreate.vue`; `ContainerDocumentController.php`

**Klart när:** `Documents.vue` saknar `<details>`-menyn i huvudet och ritar `InlineCreate` före listan; väljaren är en `<details>`-knapp och ett val laddar ingen sida; filväljaren är avstängd tills ett item är valt; formuläret står kvar efter uppladdningen; nya dokument får `bg-accent-soft` och dolda ger `container.documents.uploaded_hidden`; `maxUploadBytes` finns i svaret; sviten är grön.

**Beror på:** 367, 360

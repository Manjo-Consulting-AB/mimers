# M52 · Registrering i vyn

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12, efter genomgången av hur ny information registreras på containerns flikar *Items*, *Documents* och *To do*. Bygger [[ADR-0074 Registrering i vyn]]. Milstolpen gäller skalen och *To do*. *Items* och *Documents* får egna milstolpar när den är byggd (ADR-0074 § 7).

**Releasen:** en, när 356–359 är mergade.

---

### 356. Skalen för registrering i vyn

GitHub #1123. **Läget:** det finns ingen gemensam komponent för att registrera något i vyn. Varje flik har en egen knapp som leder bort från listan.

**Lösningen:** två presentationsskal i `resources/js/components/`. `QuickCreate.vue` är snabbraden: ett textfält som alltid syns, där Enter sparar och Escape tömmer. `InlineCreate.vue` är det infällbara formuläret: en rad med ett plus som fälls ut på samma plats och frågar innan osparade ändringar kastas. Skalen postar ingenting. Ingen vy använder dem än.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3

**Klart när:** båda komponenterna finns med de props, händelser och det tangentbordsbeteende issuen anger; fokus återgår till aktiveringsknappen när `InlineCreate` stängs; GenomgangTest är grön.

**Beror på:** —

### 357. Målväljaren begränsad till en container

GitHub #1124. **Läget:** `ItemTargetPicker` visar items i alla containrar användaren når.

**Lösningen:** en prop `container` som begränsar väljaren till en containers items. Utan propen är beteendet som i dag, så flytten, inboxens bearbetning och `/tasks/create` påverkas inte.

**Läs:** `ItemTargetPicker.vue`

**Klart när:** med `container` satt visas bara den containerns items; utan den visas alla som förut.

**Beror på:** —

### 358. Snabbraden på containerns To do-flik

GitHub #1126. **Läget:** *New task* på containerns flik leder till `/tasks/create`, en egen sida.

**Lösningen:** knappen ersätts av en snabbrad före listan. Inboxen är förvald plats, och användaren kan välja ett item i containern. Raden står kvar med fokus efter sparning. En ny rad markeras kort, och en uppgift som fliken eller filtret döljer får ett besked. Servern ändras inte.

**Läs:** [[ADR-0074 Registrering i vyn]] § 2–4 och 6, [[ADR-0054 Inboxen]] § 5 och 8

**Klart när:** containerns flik saknar länken till `/tasks/create`; raden postar till inboxen utan valt item och till itemet annars, med `return` till fliken; listan följer fliken för `next`, `waiting` och `someday`.

**Beror på:** 356, 357

### 359. Inboxens bekräftelse med länk till /inbox

GitHub #1125. **Läget:** flashen `inbox-task-captured` säger att uppgiften ligger i inboxen, men har ingen väg dit.

**Lösningen:** `FlashMessage` får en tabell från flashkod till adress, och `inbox-task-captured` får länken *Open Inbox* till `/inbox`.

**Läs:** [[ADR-0074 Registrering i vyn]] § 5

**Klart när:** bekräftelsen för `inbox-task-captured` har en länk till `/inbox`; andra koder ritas som förut.

**Beror på:** —

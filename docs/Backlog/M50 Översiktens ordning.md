# M50 · Översiktens ordning

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12, efter att containerns översikt ritats olika på staging och i produktion: *Recent activity* flyttade sig efter om containern hade bilder. Besluten står i [[ADR-0073 Översiktens ordning]].

**Ordningen:** 350 först. 351 bygger på 350. 352 bygger på 351. 353 och 354 bygger båda på 352 och kan byggas i valfri ordning.

**Releasen:** när 350–354 är stängda.

---

### 350. Panelordningen sparas per person

GitHub #1112. **Läget:** översikten har ingen sparad ordning. Placeringen räknas fram ur innehållet i `Overview.vue`.

**Lösningen:** tabellen `overview_panel` och panellistan `App\Support\Frontend\OverviewPanels` med de fem nycklarna och deras förval (ADR-0073 § 3). En action läser personens ordning med regeln för nya och borttagna nycklar (§ 8). Två webbrutter sparar och återställer ordningen och validerar enligt § 7. `DeleteUser` städar raderna. Översikten ändras inte än.

**Läs:** [[ADR-0073 Översiktens ordning]], [[Konton och åtkomst]] § overview_panel och § dismissed_tip

**Klart när:** en giltig lista sparas och läses tillbaka i samma ordning; en lista som saknar en nyckel, har en okänd nyckel, har en nyckel två gånger eller har en okänd bredd avvisas som valideringsfel och skriver ingenting; återställningen tar bort personens rader; en ny nyckel i listan står sist för den som sparat; `DeleteUser` tar bort raderna; hela testsviten är grön.

**Beror på:** —

### 351. Översikten ritas i personens ordning

GitHub #1113. **Läget:** `Overview.vue` placerar panelerna med `lg:order-*`, `grid-flow-dense` och spann som växer när *Costs* eller *Recent images* saknas (issue 288 och 321).

**Lösningen:** `ContainerController::show()` skickar proppen `panels` ur 350:s action. `Overview.vue` ritar de fem panelerna i en loop över proppen, med `lg:col-span-2` för `wide`. Varje panel ritas alltid, och *Costs* får ett tomt läge (ADR-0073 § 4). Mobilen följer samma ordning. Informationsytan står sist, utanför ordningen.

**Läs:** [[ADR-0073 Översiktens ordning]] § 2–4

**Klart när:** panelerna ritas i proppens ordning både över och under `lg:`; `wide` spänner två kolumner; *Costs* ritas med tomt läge utan kostnadsrader; *Recent activity* står på samma plats med och utan bilder; `lg:order-*` är borta ur panelerna; hela testsviten är grön.

**Beror på:** 350

### 352. Redigeringsläget med pilknappar

GitHub #1114. **Läget:** personen kan inte ändra ordningen.

**Lösningen:** knappen *Customize overview* öppnar ett redigeringsläge. Där har varje panel *Move up* och *Move down*, och läget har *Reset to default* och *Done*. *Done* sparar genom 350:s rutt (ADR-0073 § 5).

**Läs:** [[ADR-0073 Översiktens ordning]] § 5 och § 7

**Klart när:** pilarna flyttar en panel ett steg; den första panelen har ingen aktiv *Move up* och den sista ingen aktiv *Move down*; *Done* sparar och lämnar läget; *Reset to default* återställer förvalet; knapparna har 44 px träffyta och namnger panelen för skärmläsare; hela testsviten är grön.

**Beror på:** 351

### 353. Dra panelerna på skrivbordet

GitHub #1115. **Läget:** i 352:s redigeringsläge flyttas panelerna bara med pilarna.

**Lösningen:** ett draghandtag på varje panel från `lg:`, med HTML5-drag och utan npm-paket (ADR-0073 § 6). Ett släpp ändrar samma ordning som pilarna gör, och sparas med *Done*.

**Läs:** [[ADR-0073 Översiktens ordning]] § 5–6

**Klart när:** handtaget finns bara i redigeringsläget och från `lg:`; ett släpp flyttar panelen till släppets plats; ordningen sparas genom samma väg som pilarna; `package.json` är oförändrad; hela testsviten är grön.

**Beror på:** 352

### 354. Panelens bredd

GitHub #1116. **Läget:** bredden kommer bara ur förvalet.

**Lösningen:** en växel mellan `narrow` och `wide` på varje panel i redigeringsläget, sparad med *Done* (ADR-0073 § 2 och § 5).

**Läs:** [[ADR-0073 Översiktens ordning]] § 2 och § 5

**Klart när:** växeln byter bredd i läget; *Done* sparar bredden; *Reset to default* återställer bredden; hela testsviten är grön.

**Beror på:** 352

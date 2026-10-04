# M26 · Uppgifternas listor

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-04. Milstolpen bygger [[ADR-0052 Uppgifternas listor och uppgifter utan datum]]: GTD-listan och statusen på förekomsten, uppgifter utan datum och flikarna per lista. Den förutsätter listorna från issue 225–227 i [[M24 Desktopdesignen]]. Underlaget är designerns `docs/Design/Mimers_Task_Flow_GTD_Design_Decision.md` och bilderna `task-dashboard.png`, `task-new.png` och `tasks-container.png`.

**Två spår.** *Modellen och datumen:* 232 → 233 → 234. *Listorna i webben:* 232 → 235 → 236 → 237. API:et (238) kommer sist. En yta per issue: servern först och webben därefter, och API:ets skrivning för sig.

**Ingen användare kan skapa en uppgift utan datum förrän 236.** 233 gör servern tålig för null, och 234 ger raden en plats i listorna. Ordningen gör att varje release mellan dem är hel.

---

### 232. Listan och statusen i schemat

GitHub #733. `gtd_list` och `in_progress` på förekomsten och `default_gtd_list` på schemat. Varje `status = open` som betyder *inte stängd* blir *aktiv*.

**Läs:** [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 4, [[Scheman och uppgifter]]

**Klart när:** migreringen ger befintliga rader `next`; en förekomst med `in_progress` syns, kan bockas av, blockerar och räknas som försenad; inga jämförelser med `'open'` finns kvar utanför modellen; hela testsviten är grön.

**Beror på:** —

### 233. Förekomster utan datum på servern

GitHub #734. `due_at` och `visible_from` blir nullbara, och varje läsare klarar null. Notiser och ICS hoppar över en förekomst utan datum. Ännu ingen väg in för användaren.

**Läs:** [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3

**Klart när:** ett `none`-schema utan datum öppnar en förekomst utan datum; den ger ingen notis, står inte i ICS och gör inte itemet försenat; att sätta och ta bort datumet räknar `visible_from` rätt; hela testsviten är grön.

**Beror på:** 232

### 234. No date i listorna, och markören

GitHub #735. Gruppen `no_date` sist bland de öppna. Pagineringen på `/tasks` följer ADR-0052 med markörerna `d_` och `n_`. Växeln döljer bara framtida datum.

**Läs:** [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § Konsekvenser, `app/Actions/Schedule/ListTodo.php`

**Klart när:** bläddring framåt och bakåt över gränsen tappar och dubblerar inga rader; en gammal markör fungerar; *No date* syns när växeln är av; räknarna räknar inte rader utan datum; hela testsviten är grön.

**Beror på:** 233

### 235. Listans regler och en rutt för att byta lista och status

GitHub #736. En ny uppgift börjar i Inbox, förvalet lärs in en gång till Next eller Someday, och nästa förekomst ärver det. `PATCH` på förekomsten i webben.

**Läs:** [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 2

**Klart när:** varje regel i § 2 har ett prov; Waiting blir aldrig förval; en stängd förekomst kan inte ändras; en read-mottagare får 403; hela testsviten är grön.

**Beror på:** 232

### 236. Listan, statusen och det frivilliga datumet i webben

GitHub #737. Formuläret får *List* och ett frivilligt datum för en engångsuppgift, och redigeringssidan får förvalet. Raden får väljaren för lista och växeln *In progress*, och en rad utan datum säger *No date*.

**Läs:** `docs/Design/task-new.png`, `resources/js/components/ScheduleForm.vue`, `resources/js/components/TodoRow.vue`

**Klart när:** en engångsuppgift utan datum går att skapa från webben; ett återkommande schema kräver fortfarande datum; listan går att byta från raden; hela testsviten är grön.

**Beror på:** 234, 235

### 237. Flikarna per lista och GTD-panelen

GitHub #738. `?list=` på `/tasks` och containerns flik: *Active*, *Inbox*, *Next*, *Waiting*, *Calendar*, *Someday* och *Done*. En panel med antal per lista.

**Läs:** `docs/Design/task-dashboard.png`, `app/Actions/Schedule/ListTodo.php`

**Klart när:** varje flik visar rätt urval; *Done* paginerar; panelens tal följer omfånget och inte växeln; frågeantalet är konstant; hela testsviten är grön.

**Beror på:** 236

### 238. Listan och statusen i API:et

GitHub #739. `PATCH` på förekomsten i `/api`, och `gtd_list` och `default_gtd_list` vid skapande och redigering, genom samma actions som webben.

**Läs:** `app/Http/Controllers/Api/ScheduleOccurrenceController.php`, [[AGENTS.md]] § Felformat

**Klart när:** listan och statusen går att byta via API:et med samma regler; felen följer felformatet; hela testsviten är grön.

**Beror på:** 235, 236

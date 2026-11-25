# M93 · Kontoexporten

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-25 och nedbruten samma dag med Tonys svar på två frågor ur [[Tankar]]. Efter milstolpen kan personen beställa en kontoexport, och texterna i `/about` och `/privacy` säger vad exporten innehåller. Besluten står i [[ADR-0098 Kontoexporten]] och i [[ADR-0095 Integritetsbegäranden]] § Tillägg 2026-11-25:

- **Tonys beslut 2026-11-25:** Mimers får en riktig kontoexport: en ZIP med alla ägda containrar, profilen, inställningarna och loggarna som rör personen. Han valde bort två andra vägar: att containerexporten och pärmen räcker, och en knapp som startar en containerexport per container.
- **Filen** byggs av `CollectPersonData`, ligger i tabellen `export` med `kind = account` och raderas 24 timmar efter att den blivit klar.
- **Åtkomstloggen för ärendena** är händelsen `viewed`. Det gav ingen issue.

**Ordningen:** 506, sedan 507, sedan 508. 506 bygger på 501 i [[M91 Villkoren och den rättsliga spärren]]. Issuerna är skapade i GitHub (#1701–#1703) och sammanfattade här. GitHub-issuerna är bindande.

---

### 506. Kontoexportens byggare och jobb

GitHub #1701. Efteråt finns en byggare och ett jobb som skapar kontoexportens ZIP, och en rad i `export` med `kind = account`.

ZIP:en innehåller `index.html`, `account.json` och under `containers/<slug>-<ulid>/` samma tre delar som containerexporten, för de containrar kontot äger. Delade containrar listas i `shared_with_me` utan innehåll. `account.json` byggs av `CollectPersonData`, så en tystnadsbelagd spärr nämns aldrig. Migrationen gör `container_id` nullbar, lägger `account_id`, värdet `account`, CHECK-villkoret och indexet `(account_id, created_at)`. Filen gallras efter `files.account_export_retention_hours` (24), varje timme. Notisen `account_export.ready` skickas när exporten är klar.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 501

### 507. Kontoexporten på profilen och i API:et

GitHub #1702. Efteråt kan personen beställa kontoexporten på `/settings/profile` och via `/api/account/exports`.

Högst en export pågår åt gången och högst tre beställs per dygn. Varje beställning skriver `account.export_requested` i säkerhetsloggen. Exporten är fri på alla nivåer.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 506

### 508. Kontoexporten i /about och /privacy

GitHub #1703. Efteråt beskriver `/about` och `/privacy` kontoexporten, och löftet om en fullständig kopia av kontot står inte längre bara i containerexportens ord.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 507

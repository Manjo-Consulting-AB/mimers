# M92 · Relationer i papperskorgen och MariaDB-sviten

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-25 och nedbruten samma dag med Tonys svar på fyra frågor ur [[Tankar]]. Efter milstolpen kan en borttagen relation återställas ur papperskorgen, hela testsviten körs mot MariaDB 11.4 vid varje push till `main`, och `AcceptInvitation` läser inbjudan med ett uttryckligt lås. Besluten:

- **Borttagna relationer** ([[ADR-0097 Borttagna relationer i papperskorgen]]). En relation mjukraderas och hamnar i papperskorgen, som items, och gallras efter samma tid. Versionshistorik för relationer valdes bort.
- **MariaDB-sviten** ([[Pipeline]] § `.github/workflows/mariadb.yml`). Hela sviten körs mot `mariadb:11.4` vid push till `main`, inte per PR. Det gör oss redo för flytten till en egen VPS.
- **Låset i `AcceptInvitation`.** Inbjudan läses med ett uttryckligt lås i stället för `refresh()`.

Frågan om serveråtkomst för agenter gav ingen issue: svaret är *ingen*, se [[ADR-0027 Agentisolering under utveckling]] § Tillägg 2026-11-25.

**Ordningen:** 502 före 503. 504 och 505 är fristående. Issuerna är skapade i GitHub (#1695–#1698) och sammanfattade här. GitHub-issuerna är bindande.

---

### 502. Mjukraderade relationer

GitHub #1695. Efteråt mjukraderas en borttagen relation i stället för att raderas hårt, och ingen läsning eller behörighet ser den.

`item_link` får `deleted_at`, `ulid` och agentmärkningen (`agent_id`, `agent_deletion_batch`). `UnlinkItems` sätter `deleted_at`, och en agent som tar bort en relation märker raden med sin batch och räknar den mot sitt tak. Ingen läsning och ingen behörighet ser en mjukraderad rad, och en mjukraderad `parent`/`child` ger ingen åtkomst nedåt. Länkas samma par igen raderas den mjukraderade raden hårt och den nya ersätter den. En flytt till ny förälder och gallringen av ett item raderar fortfarande hårt. Gallringen tar de mjukraderade relationerna efter samma tid som items.

**Läs:** [[ADR-0097 Borttagna relationer i papperskorgen]], [[Items och organisation]] § item_link, [[ADR-0028 Åtkomst på itemnivå]], [[ADR-0087 MCP-server för agenter]]

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** —

### 503. Relationerna i papperskorgen

GitHub #1696. Efteråt syns en borttagen relation i `/trash` och kan återställas, ensam eller med agentens batch.

Papperskorgen får slaget `link`, och `/trash` får läget *Relations*. Raden syns för den som har `update` på båda ändarna, och bara när båda ändarna är levande. Återställningen prövar samma regler som `LinkItems` och skriver `item_link.restored`. Batchåterställningen tar med relationerna, efter items.

**Läs:** [[ADR-0097 Borttagna relationer i papperskorgen]], [[ADR-0065 Den samlade papperskorgen]] § Tillägg 2026-11-25, [[ADR-0087 MCP-server för agenter]]

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 502

### 504. Hela sviten mot MariaDB på main

GitHub #1697. Efteråt kör `.github/workflows/mariadb.yml` hela testsviten mot `mariadb:11.4` vid push till `main` och manuellt.

Jobbet blockerar ingenting, och `migreringar.yml` står kvar för PR:er som rör en migrering. Skälet till `main` och inte per PR står i Pipeline.

**Läs:** [[Pipeline]] § `.github/workflows/mariadb.yml` och § `.github/workflows/migreringar.yml`

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —

### 505. Uttryckligt lås i AcceptInvitation

GitHub #1698. Efteråt läser `AcceptInvitation` inbjudan med ett uttryckligt lås i stället för `refresh()`, så att kapplöpningen mot en ändring av nivån på en väntande inbjudan stängs på databasnivå.

**Läs:** [[Konton och åtkomst]] § invitation

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

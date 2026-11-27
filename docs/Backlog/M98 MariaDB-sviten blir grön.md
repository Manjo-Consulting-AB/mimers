# M98 · MariaDB-sviten blir grön

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-26. Efter milstolpen är hela sviten grön mot MariaDB 11.4. Fynden kommer från körningarna av `mariadb.yml`, se [[M92 Relationer i papperskorgen och MariaDB-sviten]] § 504. Ett av dem är ett fel i produktionen: taggfiltret ger 500 på MariaDB. De andra är prov som bygger på SQLite.

**Ordningen:** alla tre är fristående. GitHub-issuerna är bindande.

---

### 523. Taggfiltret fälls av ONLY_FULL_GROUP_BY på MariaDB

GitHub #1759. `Item::scopeWithAllTags()` gör `select item.*` med `group by item.id`, vilket MariaDB avvisar i strikt läge. Grupperingen flyttas till en underfråga mot `item_tag`. Det fäller 24 prov och är med största sannolikhet trasigt i produktionen sedan issue 15a.

**Läs:** [[Pipeline]] § `.github/workflows/mariadb.yml`

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** —

### 524. MariaDB-sviten, prov som bygger på SQLites schema och DDL

GitHub #1760. Det gäller nio prov. Fixturerna använder värden som CHECK-villkoren avvisar (`inbox`, `once`, `sibling`, `x<n>`), ett prov använder `PRAGMA`, ett använder ett datum bortom TIMESTAMP-gränsen 2038 och ett kräver drivrutinen `sqlite`. Prov som kör DDL räknar med att transaktionen rullar tillbaka den, vilket MariaDB inte gör.

**Läs:** [[Pipeline]] § `.github/workflows/mariadb.yml`

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —

### 525. MariaDB-sviten, prov som bygger på SQLites citattecken och kollation

GitHub #1761. Det gäller sju prov. Frågeloggen matchas mot `"tabell"` i stället för backticks, normaliseringen av e-post bevisas med en `where` som `utf8mb4_unicode_ci` inte skiljer på, och sorteringen av Å skiljer sig mellan drivrutinerna.

**Läs:** [[Pipeline]] § `.github/workflows/mariadb.yml`

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —

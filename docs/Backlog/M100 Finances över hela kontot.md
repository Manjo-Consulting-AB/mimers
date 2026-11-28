# M100 · Finances över hela kontot

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-28. Efter milstolpen finns `/finances` i huvudmenyn. Sidan är containerns flik *Finances* över allt användaren når och är också rapportvyns yta. Besluten står i [[ADR-0103 Finances över hela kontot]], som bygger på [[ADR-0038 Gränsen för Pro i kostnaderna]] och [[ADR-0102 Transaktioner på items]].

**Ordningen:** en rak följd, 533, 534, 535, 536. Docs-issuen är GitHub #1804. Issuerna är skapade i GitHub (#1805–#1808) och sammanfattade här. GitHub-issuerna är bindande.

---

### 533. Motorn och urvalet för Finances över flera containrar

GitHub #1805. Efteråt räknar och listar `CostReport` och `ListAccessibleTransactions` transaktioner över flera containrar. `ResolveFinanceScopes` avgör per container, på containerns konto, vilka containrar som ingår i rapporten.

**Läs:** [[ADR-0103 Finances över hela kontot]], [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** docs-issuen #1804

### 534. Sidan /finances med den fria halvan och raden i menyn

GitHub #1806. Efteråt finns `GET /finances` med brickorna per valuta, donuten per container och raderna, raden *Finances* i menyn och rapportens proppar.

**Läs:** [[ADR-0103 Finances över hela kontot]]

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 533

### 535. Rapportvyn på /finances

GitHub #1807. Efteråt ritar sidan Pro-halvan: filterraden med containrar, grafen, nedbrytningen, jämförelsen, raden om containrar utanför rapporten och uppgraderingsytan.

**Läs:** [[ADR-0103 Finances över hela kontot]] Riktning 4, 5 och 7

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 534

### 536. Hjältens kostnadssumma leder till /finances, och hjälpartikeln

GitHub #1808. Efteråt är hjältens kostnadssumma en länk till `/finances`, och hjälpartikeln om kostnader beskriver sidan.

**Läs:** [[ADR-0103 Finances över hela kontot]] Riktning 5 och 7

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 534

# M99 · Transaktioner på items

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-27. Efter milstolpen kan en kostnadsrad vara en intäkt, och itemets och containerns flik *Finances* visar utgifter, intäkter och netto. Besluten står i [[ADR-0102 Transaktioner på items]], som vidgar [[ADR-0016 Kostnadsregistrering]].

**Ordningen:** 526 först. 527 och 528 beror på 526. 529 beror på 526 och 527, och 530 beror på 527 och 529. 531 beror på 526 och 527. 532 kommer sist. Issuerna är skapade i GitHub (#1778–#1784) och sammanfattade här. GitHub-issuerna är bindande.

---

### 526. Kostnadsraden får riktning, antal och motpart

GitHub #1778. Efteråt har `cost_entry` kolumnerna `kind` (`expense` eller `income`, förvalt `expense`) och `quantity`, och `supplier` heter `counterparty`.

**Läs:** [[ADR-0016 Kostnadsregistrering]] § Beslut

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** —

### 527. Summeringarna och rapporten bär utgifter, intäkter och netto

GitHub #1779. Efteråt bär de fasta summeringarna per valuta utgifter, intäkter och netto, donutarna räknar bara utgifter, och rapportvyn har `kind` som filter.

**Läs:** [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 526

### 528. MCP-verktygen transactions_*, costs_* avvecklas

GitHub #1780. Efteråt finns `transactions_create`, `transactions_list`, `transactions_update` och `transactions_delete`, och `costs_*` står kvar i 90 dagar som föråldrade och läser och skriver bara utgifter. `/transactions`-rutterna läggs till på samma sätt.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 15, verktygen som kontrakt

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 526

### 529. Itemets flik Finances, intäkter i formuläret och summan

GitHub #1781. Efteråt heter fliken *Finances*, formuläret har valet *Expense / Income* med *Expense* förvalt, och summan visar netto bara när itemet har intäkter.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 526, 527

### 530. Containerns sida Finances, riktningsfilter och netto

GitHub #1782. Efteråt heter containerns sida *Finances*, och den har riktningsfilter och kolumnerna utgift, intäkt och netto.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 527, 529

### 531. Pärmen och hjälpartikeln visar intäkter

GitHub #1783. Efteråt visar pärmen och hjälpartikeln intäkter bredvid utgifter.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 526, 527

### 532. Internt namnbyte cost_entry till item_transaction

GitHub #1784. Efteråt heter tabellen `item_transaction` och modellen `ItemTransaction`. Inget annat i M99 beror på den.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 526–531

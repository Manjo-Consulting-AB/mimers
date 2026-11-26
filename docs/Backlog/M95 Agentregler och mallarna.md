# M95 · Agentregler och mallarna

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-26. Efter milstolpen kan användaren och containerns ägare ge agenten regler i fritext, och mallarna kan bara komma från kontot eller från Mimers. Besluten står i [[ADR-0100 Agentregler]] och [[ADR-0101 Mallarna]]:

- **Tonys beslut 2026-11-26 (regler):** Två fält, användarens och containerns. Bara containerns ägare skriver containerns regler. En agent har ingen skrivrätt, så reglerna skrivs bara i appen.
- Reglerna kan bara snäva in vad agenten gör, levereras utan att agenten ber om dem och bärs i fältet `agent_rules`, som säger vem som skrivit dem. De är det enda undantaget från att all text är användardata ([[ADR-0087 MCP-server för agenter]] § Riktning 9).
- **Tonys beslut 2026-11-26 (mallar):** Bara användarens sparade mallar och Mimers mallar finns. Klienten skickar aldrig en malls innehåll, bara en ULID eller en nyckel; `template_content` tas bort. En mall läggs bara på när en container skapas. En sparad mall bär containerns agentregler, med kryssrutan *Include agent rules* förvald på.

**Ordningen:** 512 och 515 först (oberoende av varandra), sedan 513, 514 och 516, sist 517. Issuerna är skapade i GitHub (#1730–#1732 och #1735–#1737) och sammanfattade här. GitHub-issuerna är bindande.

---

### 512. Agentreglerna i databasen, revisionerna och behörigheten

GitHub #1730. Efteråt finns `user.agent_rules`, `container.agent_rules` och tabellen `agent_rules_revision`, och bara containerns ägare får skriva containerns regler.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** -

### 513. Agentreglerna når agenten via MCP

GitHub #1731. Efteråt får agenten användarens regler i serverns `instructions` och i `containers_list`, och containerns i `containers_list` per container och i `containers_tree`, i fältet `agent_rules`.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 512

### 514. Agentreglerna på inställningssidan och containerns agentsida

GitHub #1732. Efteråt skriver användaren sina regler på inställningssidan, och containerns ägare containerns regler på containerns agentsida.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 512

### 515. Mimers mallar på servern och template_content bort

GitHub #1735. Efteråt ligger Mimers mallar på servern med en nyckel, klienten skickar en ULID eller en nyckel, och `template_content` är borta.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** -

### 516. En mall läggs bara på när containern skapas

GitHub #1736. Efteråt finns ingen väg att lägga en mall på en befintlig container: `POST /containers/{id}/template`, `containers_apply_template` och `POST /containers/{id}/categories/preset` är borta.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 515

### 517. Mallen bär containerns agentregler

GitHub #1737. Efteråt bär en sparad mall containerns agentregler, med kryssrutan *Include agent rules* förvald på vid sparandet.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 512, 515, 516

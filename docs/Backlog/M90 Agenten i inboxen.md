# M90 · Agenten i inboxen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-25 efter att Tony tagit upp att en agent som ansluter via MCP kan arbeta i containrar men inte i inboxen. Efter milstolpen kan användaren slå på sin agent i inboxen på `/settings/agents`, och agenten kan hämta och bearbeta det som ligger där. Besluten står i [[ADR-0087 MCP-server för agenter]] § Tillägg 2026-11-25: agenten i inboxen och i [[ADR-0054 Inboxen]] § 2:

- **Agenten slås på på `/settings/agents`**, bara i grupperna `schedules` och `files`, och förvalet är av.
- **Kopieringsspärren mjukas upp för inboxen.** Det agenten läser där räknas inte i snittet (ADR-0087 Riktning 10).
- **Agenten kan flytta saker ur inboxen** till ett riktigt item, även i en delad container, genom samma actions som appen.
- **Inboxen bär inga items.** Issue 498 stänger ett hål som fanns före milstolpen: ett barn under inboxens item släpps i dag igenom på webben, i REST och i MCP.

**Ordningen:** 497 bygger på 496. 495, 496 och 498 beror inte på något. Issuerna är skapade i GitHub (#1685, #1686, #1688 och #1689) och sammanfattade här. GitHub-issuerna är bindande.

---

### 495. Agenten i inboxen på /settings/agents

GitHub #1685. Efteråt kan användaren slå på sin agent i inboxen med en väljare per grupp (`schedules` och `files`) i avsnittet *Inbox* på agentens sida, och valet sparas direkt. `ApplyAgentContainerGrants` skriver bara de två grupperna för en inbox, oavsett väg in, och `/containers/{inbox}/agents` svarar 404. Förvalet är av.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

### 496. inbox_get och inboxen utanför containers_list

GitHub #1686. Efteråt ger verktyget `inbox_get` inboxens item och, med `files`, dess bilagor, så att uppgifts- och bilageverktygen får ett ULID att arbeta med. Är agenten inte påslagen ger det felet `inbox.not_enabled` med en `hint`. `containers_list` hoppar över inboxen, och det som agenten läser ur inboxen räknas inte i kopieringsspärren (ADR-0087 Riktning 10).

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

### 497. Agenten bearbetar inboxen

GitHub #1688. Efteråt finns verktygen `inbox_tasks_process` (gruppen `schedules`) och `inbox_attachments_process` (gruppen `files`), som bearbetar inboxen genom `ProcessInboxTask` och `ProcessInboxAttachments`. De kräver `delete` i gruppen på inboxen och `create` på målet, som i appen ([[ADR-0054 Inboxen]] § 6), och prövas varken mot flyttspärren eller kopieringsspärren.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 496

### 498. Inget item i inboxen

GitHub #1689. Efteråt kan inget barn skapas under inboxens item, varken på webben, i REST eller i MCP. Regeln sitter i `CreateItem`, eftersom `ItemPolicy::create` på föräldern ger ägaren `create` och därför inte kan neka ([[ADR-0054 Inboxen]] § 2). Det stänger ett hål som fanns före milstolpen.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

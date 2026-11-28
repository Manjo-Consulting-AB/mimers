### Milstolpe

M66 MCP-server för agenter · backlog-issue 406

### Mål

En agent med `delete` kan radera, allt hamnar i papperskorgen, och det agenten raderat syns och går att återställa på en gång. Bygger [[ADR-0087 MCP-server för agenter]] Riktning 7 (papperskorgen), 8, 9 (raderingstaket) och 14 (`agent.deleted`).

**1. Verktygen** `items_delete`, `attachments_delete`, `schedules_delete`, `costs_delete` och `containers_delete`, med slaget `delete` och `destructiveHint: true`. De anropar samma actions som appen, och allt hamnar i papperskorgen. `containers_delete` släpps bara igenom av `ContainerPolicy::delete`. Inget verktyg tömmer eller rensar papperskorgen.

**2. Tillfället.** Varje radering från en agent bär `deleted_by_agent_id` och `agent_deletion_batch` (en ULID). En ny batch börjar när det har gått mer än 15 minuter sedan agentens förra radering. Kolumnerna läggs på varje tabell som papperskorgen listar. Bara en lyckad radering räknas. Ett försök som avvisas med `agent.delete_limit` förlänger inte batchen, annars skickas notisen aldrig och taket släpps aldrig.

**3. Papperskorgen.** En rad som en agent raderat visar *Deleted by {agentens namn}*. Papperskorgen kan återställa hela batchen med en åtgärd, `RestoreAgentDeletionBatch`, bakom appens bekräftelsedialog.

**4. Notisen `agent.deleted`.** Skapas 15 minuter efter batchens sista radering (`available_at`, `dedupe_key` med agenten och batchen) och går till agentens användare, och till containerns ägare när det är någon annan. Den räknar upp vad som raderats och länkar till papperskorgen. För en raderad container står också hur många items den innehöll, eftersom de följer med till papperskorgen fast taket räknar containern som en radering. Notisen i appen går inte att stänga av. Mejlet kan stängas av i notisinställningarna.

**5. Raderingstaket.** Efter 20 raderingar inom en timme från samma agent avvisas fler med `agent.delete_limit`. Taket släpps när användaren har öppnat notisen `agent.deleted` för batchen i appen, inte efter en viss tid.

### Läs

[[ADR-0087 MCP-server för agenter]] § Riktning 7, § Riktning 8, § Riktning 9, § Riktning 13 och § Riktning 14

[[Notiser]] § notification och § notification_preference

[[ADR-0077 Notislistan i appen]]

`app/Actions/Trash/ListTrash.php` och `app/Actions/Trash/RestoreContent.php`

### In scope

```shell
database/migrations/*_add_agent_deletion_to_trashable_tables.php
app/Mcp/**
app/Support/Mcp/**
app/Actions/Trash/**
app/Actions/Agent/**
app/Actions/Notification/**
app/Support/Notification/NotificationPreferences.php
app/Models/**
app/Http/Controllers/TrashController.php
app/Http/Controllers/ContainerTrashController.php
routes/web.php
resources/js/pages/Trash/**
resources/js/pages/Notifications/**
lang/en/ui.php
lang/en/notiser.php
tests/Feature/Mcp/**
tests/Feature/Trash/**
app/Models/Notification.php
tests/Feature/Frontend/SprakTest.php
```

### Omfångsläge

spårad

### Out of scope

```shell
app/Actions/Trash/EmptyTrash.php
app/Actions/Trash/PurgeContainer.php
app/Actions/Trash/PurgeContent.php
app/Policies/**
routes/api.php
docs/**
.github/**
```

### ambiguity

high

### blast_radius

cross-module

### risk_class

elevated

### Granskning

Opus, läser issuens läslista (risk_class elevated)

### Klart när

- [ ] Det en agent raderar hamnar i papperskorgen med agentens namn och kan återställas.
- [ ] Raderingar med högst 15 minuter emellan får samma batch, och en radering efter mer än 15 minuter får en ny.
- [ ] `RestoreAgentDeletionBatch` återställer hela batchen och inget annat.
- [ ] `agent.deleted` skapas en gång per batch, 15 minuter efter den sista raderingen, till agentens användare och till en annan ägare.
- [ ] Notisen i appen går inte att stänga av. Mejlet går att stänga av.
- [ ] Den 21:a raderingen inom en timme ger `agent.delete_limit`, och taket släpps när användaren öppnat notisen.
- [ ] Avvisade försök efter taket förlänger inte batchen. Notisen skapas 15 minuter efter den sista lyckade raderingen.
- [ ] Notisen för en raderad container anger hur många items den innehöll.
- [ ] `containers_delete` på någon annans container avvisas.
- [ ] Inget verktyg når `EmptyTrash`, `PurgeContent` eller `PurgeContainer`.
- [ ] Testerna täcker punkterna, och hela sviten är grön.

### Beror på

#1391, #1386

### Delmoment

- [ ] En session




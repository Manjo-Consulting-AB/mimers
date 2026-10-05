# M27 · Flytten och inboxen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-05, efter genomgången av uppgifterna på staging (v0.20.0). Milstolpen bygger [[ADR-0053 Flytt och kopiering]] och [[ADR-0054 Inboxen]]: flytt och kopiering i hela systemet, en personlig inbox för det som saknar hemvist, och knappen *New task*, som saknades på staging.

**Två spår som möts.** *Flytten:* 239 → 240 → 243. *Inboxen:* 241 → 242 → 244 → 245 → 246. API:et (247) kommer sist. Varje issue står i läget `spårad`, så att en testfil som påverkas indirekt kan ändras och redovisas i PR:en utan att agenten behöver stanna.

**Inboxen syns aldrig i målväljaren.** *Back to Inbox* är en egen handling (244), och den finns i webben och i API:et.

---

### 239. Flytta och kopiera en bilaga, servern

GitHub #752. `MoveAttachment` och `CopyAttachment`, med handlingens konto som ägare, kvoten prövad när ägaren byts och en rad i båda containrarnas logg.

**Läs:** [[ADR-0053 Flytt och kopiering]]

**Klart när:** flytt inom containern rör inte kvoten; flytt till ett annat konto byter ägare och flyttar bytena; en kopia belastar kvoten även inom samma konto; loggarna nämner inte motparten; hela testsviten är grön.

**Beror på:** —

### 240. Flytta en uppgift, servern

GitHub #753. `MoveSchedule`: historiken följer med, en flytt till en annan container nekas vid beroenden, och notiserna frigörs.

**Läs:** [[ADR-0053 Flytt och kopiering]] § 6

**Klart när:** förekomsterna följer med; flytt till en annan container nekas vid beroenden; kostnadsraderna stannar; hela testsviten är grön.

**Beror på:** 239

### 241. Inboxen i schemat och åtkomsten

GitHub #754. `container.inbox_user_id`, `ResolveInbox`, regel 0 i omfångsupplösningen och `listable()` för listorna över containrar.

**Läs:** [[ADR-0054 Inboxen]] § 1–3 och § 9

**Klart när:** bara ägaren når inboxen, inte andra medlemmar i personkontot; inboxen syns inte i någon lista över containrar men dess uppgifter syns på `/tasks`; den räknas inte mot containertaket; hela testsviten är grön.

**Beror på:** —

### 242. Väljaren för målitem

GitHub #755. `ItemTargetPicker` och den lata proppen `itemTargets`: items där användaren har `create`, i alla containrar hon når, aldrig någon inbox.

**Läs:** `resources/js/components/ParentPicker.vue`

**Klart när:** bara items med `create` visas; den egna inboxen visas inte; antalet frågor är konstant; hela testsviten är grön.

**Beror på:** 241

### 243. Flytta och kopiera i webben

GitHub #756. *Move…* och *Copy…* på bilagans rad, och *Move…* på uppgiftens sida.

**Läs:** `resources/js/components/ItemAttachmentSection.vue`

**Klart när:** handlingarna ritas efter grindarna; ett fel ritas på raden; hela testsviten är grön.

**Beror på:** 239, 240, 242

### 244. Inbox som plats i GTD-modellen

GitHub #757. `gtd_list` utan `inbox`, vyerna *Inbox* och *In progress*, bearbetningen av en uppgift och *Back to Inbox*.

**Läs:** [[ADR-0054 Inboxen]] § 5 och § 6

**Klart när:** migreringen gör `inbox` till `next` i båda kolumnerna; en uppgift på ett item får *Next*; *Waiting* blir aldrig förval; *Back to Inbox* nollställer listan och förvalet; hela testsviten är grön.

**Beror på:** 239, 240, 241, 243

### 245. Sidan /inbox

GitHub #758. Fånga filer och uppgifter, bearbeta en i taget eller flera bilagor på en gång, och visa antalet i sidopanelen.

**Läs:** [[ADR-0054 Inboxen]] § 4, § 6–8

**Klart när:** sidan skapar ingen inbox; flera bilagor flyttas alla eller ingen; kvoten prövas för summan; hela testsviten är grön.

**Beror på:** 239, 242, 244

### 246. New task

GitHub #759. Knappen på itemets och containerns flik och på `/tasks`, och formuläret `/tasks/create` med fältet *Place*, där *Inbox* är förvalt utan item.

**Läs:** `docs/Design/tasks-container.png`, [[ADR-0054 Inboxen]] § 8

**Klart när:** de tre ytorna har knappen; en uppgift utan plats hamnar i inboxen; en uppgift med plats får *Next*; hela testsviten är grön.

**Beror på:** 242, 245

### 247. Flytt och inbox i API:et

GitHub #760. Flytt, kopiering, inboxen, bearbetningen och *Back to Inbox* i `/api`, genom samma actions som webben.

**Läs:** [[AGENTS.md]] § Felformat

**Klart när:** varje handling i webben finns i API:et med samma regler och felkoder; hela testsviten är grön.

**Beror på:** 239, 240, 244, 245

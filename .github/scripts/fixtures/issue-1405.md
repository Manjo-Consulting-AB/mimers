### Milstolpe

M67 Versionshistorik · backlog-issue 412

### Mål

Varje ändring av ett item sparar läget före ändringen som en version, inom taket, med täta skrivningar sammanslagna, och utan att en full kvot hos ägaren stoppar skrivningen. Bygger [[ADR-0088 Versionshistorik för items]] Riktning 2, 3, 4 och 6 (taket och spärren).

**1. `App\Actions\Item\RecordItemVersion`.** Anropas i samma transaktion som skrivningen, **före** `MetadataLedger::save($item)`, av `UpdateItem` och `UpdateItemNotes`. `SyncItemTags` anropas bara av `CreateItem` och `UpdateItem`, och ett nytt item har ingen tidigare version, så `UpdateItem` jämför själv taggmängden före och efter och sparar **en** version för fälten och taggarna tillsammans. Den tar itemet, aktören (`User`) och en valfri `?int $agentId` (fylls i av 415). Inget sparas när `VersionLimit::for` ger 0 eller när ingenting ändras (`isDirty()` falskt och samma taggmängd).

**2. Ögonblicksbilden** är fälten ur ADR-0088 Riktning 2 som de står i databasen före ändringen (`getRawOriginal()`, eller raden läst under lås som i `MetadataLedger`), och `tag_ids` sorterade. `billed_account_id` = `container.account_id`.

**3. Skurven.** Har itemets nyaste version samma `replaced_by_user_id` och `replaced_by_agent_id` som aktören och `replaced_at` inom 10 minuter sparas ingen ny version. `replaced_at` sätts i stället till nu. Fönstret ligger i config (`versioning.burst_minutes` = 10). En återställning (413) bryter alltid skurven, genom en parameter `breakBurst: true`.

**4. Taket.** Efter en ny version tas itemets äldsta versioner bort tills antalet är högst `VersionLimit::for`. Borttagningen går genom `MetadataLedger::release()`.

**5. Full kvot.** Versionen prövas mot ägarens kvot. Ryms den inte tas itemets äldsta versioner bort en i taget tills den ryms. Ryms den ändå inte sparas ingen version, och skrivningen går igenom ändå: aktörens egen skrivning prövas mot aktörens kvot som förut. Ägaren får notisen `versioning.skipped` i appen, med `dedupe_key` för containern och dygnet. Notisen säger att lagringen är full och länkar till `/settings/storage`.

**6. Rättslig spärr.** Står ägarkontot under spärr (`LegalHold::covers`) tas inga versioner bort i punkt 4 och 5. Ryms versionen inte under spärr sparas den ändå, utan kvotprövning, så att inget raderas. Inget i svaret eller i vyerna avslöjar spärren.

### Läs

[[ADR-0088 Versionshistorik för items]] § Riktning 2, § Riktning 3, § Riktning 4 och § Riktning 6

[[ADR-0066 Itemets anteckning]] § 10 (arbetspasset i `UpdateItemNotes`)

[[Notiser]] § notification

`app/Actions/Item/UpdateItem.php`, `app/Actions/Item/UpdateItemNotes.php` och `app/Actions/Item/SyncItemTags.php` (läses, ändras inte)

`app/Support/Usage/MetadataLedger.php` (`save`, `release` och `assertFits`)

`app/Actions/Trash/EmptyTrash.php` (hur `LegalHold::covers` används)

### In scope

```shell
app/Actions/Item/RecordItemVersion.php
app/Actions/Item/UpdateItem.php
app/Actions/Item/UpdateItemNotes.php
app/Support/Versioning/**
app/Support/Usage/MetadataLedger.php
app/Actions/Notification/**
config/konton.php
lang/en/notiser.php
lang/en/ui.php
tests/Feature/Versionering/**
```

### Omfångsläge

spårad

### Out of scope

```shell
app/Http/Controllers/**
resources/**
app/Actions/OwnershipTransfer/**
app/Actions/Trash/**
app/Actions/Item/SyncItemTags.php
app/Actions/Item/CreateItem.php
app/Policies/**
routes/**
app/Mcp/**
docs/**
.github/**
```

### ambiguity

low

### blast_radius

cross-module

### risk_class

elevated

### Granskning

Opus, läser issuens läslista (risk_class elevated)

### Klart när

- [ ] En ändring av beskrivningen sparar en version med den gamla beskrivningen och de gamla taggarna, och `billed_account_id` är containerns ägare även när en inbjuden skriver.
- [ ] En ändring som inte ändrar något sparar ingen version.
- [ ] Med taket 0, på kontot eller containern, sparas ingen version.
- [ ] Tre skrivningar från samma aktör med 5 minuter emellan ger en version med läget före den första. Flyttas klockan 11 minuter efter den sista ger nästa skrivning en ny version.
- [ ] En skrivning från en annan användare inom fönstret ger en ny version.
- [ ] Med taket 3 har itemet aldrig fler än 3 versioner, och de äldsta tas bort.
- [ ] Anteckningens autosparning ger en version per arbetspass.
- [ ] Ett taggbyte utan andra ändringar sparar en version.
- [ ] Med ägarens kvot full går skrivningen igenom. De äldsta versionerna tas bort för att ge plats, och ryms den ändå inte sparas ingen version och `versioning.skipped` skapas en gång per container och dygn.
- [ ] Under rättslig spärr tas inga versioner bort.
- [ ] `metadata_bytes` stämmer med avstämningen efter varje fall ovan.
- [ ] Testerna täcker punkterna, och hela sviten är grön.

### Beror på

#1404, #1403, #1436 (backlog 420 i M68: `item_version` ska byggas med fältvärdena i stället för de sex kolumnerna, se ADR-0089)

### Delmoment

- [ ] En session




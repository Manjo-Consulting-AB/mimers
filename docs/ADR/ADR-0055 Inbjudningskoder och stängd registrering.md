# ADR-0055 Inbjudningskoder och stängd registrering

**Status:** Antagen 2026-10-07 · Bygger på [[ADR-0011 Autentisering]], [[ADR-0014 Prismodell]] och [[ADR-0045 Radering av konto och person]] · [[ADR-index]]

## Kontext

Mimers är i en privat beta. Registreringen är öppen för vem som helst, på webben (`POST /register`) och i API:et (`POST /api/register`). Testarna ska komma in på inbjudan och få Pro, och den dag vi går live ska registreringen kunna öppnas utan att flödet byggs om.

Pro ges redan i dag som data och inte som ett undantag i planlogiken: en `subscription`-rad med plan `pro` och status `active`. `GrantInternalPro` och ägarbytets bonus gör så.

Containerinbjudningar skickar en ny person till `/register`. Stängs registreringen helt kan testarna inte dela med någon som saknar konto.

## Beslut

### 1. Registreringens läge är en inställning

`config('konton.registration')` har värdet `open` eller `invite_only`, ur `ACCOUNT_REGISTRATION`. Förvalet är `invite_only`. Att öppna registreringen är att byta värdet, och ingenting annat.

### 2. Koden är inbjudan

I läget `invite_only` krävs en giltig kod för att registrera sig. Samma kod ger den plan den bär. Det finns inte två begrepp — en inbjudan och en rabattkod — utan ett: en **voucher**.

I läget `open` är koden frivillig. En giltig kod ger sin plan; en ogiltig nekar registreringen, så att ingen tror att hon fått Pro när hon inte fått det.

### 3. En containerinbjudan är en egen väg in

Den som registrerar sig med en utestående containerinbjudan får registrera sig utan kod, och får Free. Inbjudan prövas med sin token, inte bara med e-postadressen: registreringen kräver ingen verifierad adress, och en adress räcker därför inte som bevis på att man fått mejlet.
- **Webben:** token som `InvitationResponseController` redan sparar i sessionen (`pending_invitation_token`).
- **API:et:** fältet `invitation_token`.

Inbjudans e-postadress måste vara densamma som registreringens, skiftlägesokänsligt. Inbjudan accepteras inte av registreringen; det sker som i dag.

Anger personen dessutom en giltig kod får hon kodens plan.

### 4. Datamodellen

**`voucher`**

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| code_hash | CHAR(64) UNIQUE | SHA-256 av den normaliserade koden. Koden själv sparas aldrig. |
| label | VARCHAR(191) NULL | vem eller vad koden gavs till |
| plan_id | FK → plan | |
| duration_days | INT UNSIGNED | hur länge planen gäller från inlösen |
| max_uses | INT UNSIGNED | |
| used_count | INT UNSIGNED | räknas upp i inlösens transaktion |
| grants_registration | BOOLEAN | om koden släpper in i läget `invite_only` |
| expires_at | TIMESTAMP NULL | sista dag koden kan lösas in |
| revoked_at | TIMESTAMP NULL | |
| created_at, updated_at | | |

**`voucher_redemption`**

| Kolumn | Typ | Not |
|---|---|---|
| id | | |
| voucher_id | FK → voucher, RESTRICT | |
| account_id | FK → account NULL, SET NULL | |
| user_id | FK → user NULL, SET NULL | |
| created_at, updated_at | | |

Unikt index på (`voucher_id`, `account_id`).

`SET NULL` är ett medvetet undantag från RESTRICT. En raderad person eller ett raderat konto ([[ADR-0045 Radering av konto och person]]) ska inte hindras av en inlösen, och raden står kvar som historik över att koden använts.

Ingen soft delete: en voucher är inte användarskapat innehåll, och `revoked_at` är dess livscykel.

### 5. Koden

Formen är `XXXX-XXXX-XXXX`: tolv tecken ur Crockfords base32, 60 bitar slump. Den normaliseras före hashningen till versaler utan bindestreck och mellanslag, och `O`, `I` och `L` läses som `0`, `1` och `1`. Koden visas en enda gång, när den skapas.

### 6. Inlösen

En action, `RedeemVoucher`, anropas av båda registreringsvägarna i samma transaktion som kontot skapas. Den
- låser vouchern med `lockForUpdate`
- prövar att den inte är återkallad, inte har gått ut och har uttag kvar
- räknar upp `used_count`
- skriver `voucher_redemption`
- ger planen

Planen ges som i `GrantInternalPro`: en `subscription`-rad med kodens plan, status `active`, `current_period_end` = nu + `duration_days`, `grace_until` = null och `external_ref` = `voucher`. Har kontot redan en aktiv rad med samma plan och senare slutdatum — ett internt Pro — lämnas den orörd.

### 7. Hur koder skapas

Med Artisan över SSH, ingen administrationsyta:
- `voucher:create --label= --plan=pro --days=365 --uses=1 --expires= --no-registration` skriver ut koden
- `voucher:revoke {code}`
- `voucher:list` visar etikett, plan, uttag och läge, aldrig koden

Kommandona körs för hand och schemaläggs inte; de berörs därför inte av att `proc_open` saknas. Förvalen är betans: Pro, 365 dagar, ett uttag och `grants_registration`.

### 8. Felen

- En ogiltig, återkallad, utgången eller förbrukad kod ger samma svar: valideringsfel på fältet `voucher_code` med regeln `RedeemableVoucher`, alltså `validation.redeemable_voucher` i API:et. Svaret skiljer inte fallen åt, så att det inte går att pröva sig fram till vilka koder som finns.
- I läget `invite_only`, utan kod och utan en giltig inbjudan, är fältet obligatoriskt: `validation.required` på `voucher_code`.

## Motivering

**En kod per testare** (Tonys beslut 2026-10-07). Vi ser vem som fått vilken kod, kan återkalla och ersätta en enskild kod, och kan i efterhand se hur testarna kom in. Modellen bär ändå en gemensam kampanjkod med `max_uses` > 1 den dag den behövs.

**365 dagar och inte för alltid.** Tid nog för en utdragen beta, utan att en betakod blir ett permanent Pro.

**Hashade koder**, som inbjudningarnas `token_hash`: en läckt databasdump ska inte innehålla inlösbara koder.

**Läget är en inställning och inte en voucherregel.** Att öppna registreringen och att dela ut Pro är två olika beslut, och de ska kunna fattas var för sig.

## Konsekvenser

- **Pro löper ut genom planberäkningen, inte genom ett jobb** (Tonys beslut 2026-10-07). En `active`-prenumeration vars `current_period_end` passerat räknas som Free överallt där planen läses. Regeln står på ett ställe, `Subscription::isCurrent()`, och ingen rad skrivs om. `past_due` bär sin plan oavsett datum, eftersom en utebliven betalning hanteras av nedgraderingen. Regeln gäller också ägarbytets bonusår, som hade samma lucka. Ett konto över gratisgränsen efter utgången får sina nya uppladdningar nekade av kvoten, men inga filer raderas. Byggs i issue 265, före koderna delas ut.
- **Två registreringar med samma enkla kod samtidigt.** Den andra nekas i transaktionen, efter att valideringen godkänt koden. Det felet bär `validation.invalid` på `voucher_code` i API:et i stället för `validation.redeemable_voucher`. Fallet är sällsynt nog att det inte motiverar mer.
- **Befintliga användare kan inte lösa in en kod** i inställningarna. Modellen bär det, men ytan byggs när den behövs.

## Alternativ

- **En gemensam betakod.** Enklare att dela ut, men går inte att spåra eller återkalla per person.
- **Två begrepp, en inbjudningskod och en Pro-voucher.** Mer att bygga, och ingenting vunnet i betan.
- **Stänga registreringen helt och skapa testarnas konton för hand.** Då hade delning med någon utan konto inte gått att testa.
- **E-postadressen som bevis för en containerinbjudan.** Registreringen kräver ingen verifierad adress, så adressen bevisar ingenting.

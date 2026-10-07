# M29 · Privat beta

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-07. Registreringen stängs för betan: en individuell inbjudningskod släpper in och ger Pro i 365 dagar, och en containerinbjudan är en egen väg in till Free. Bygger [[ADR-0055 Inbjudningskoder och stängd registrering]].

**Ordningen är 262 → 263 → 264.** 263 och 264 ska ut i samma release, annars står API:et öppet medan webben är stängd. Efter releasen skapar Tony testarnas koder med `php artisan voucher:create --label=…` över SSH.

---

### 262. Vouchrar och stängd registrering, servern

GitHub #788. `voucher` och `voucher_redemption`, `RedeemVoucher`, `AdmitRegistration`, regeln `RedeemableVoucher`, inställningen `konton.registration` och Artisan-kommandona.

**Läs:** [[ADR-0055 Inbjudningskoder och stängd registrering]]

**Klart när:** koden sparas bara som hash; inlösen ger Pro i 365 dagar; en förbrukad kod nekas; en containerinbjudan släpper in utan kod; `voucher:list` visar aldrig koden; hela testsviten är grön.

**Beror på:** —

### 263. Privat beta i webbens registrering och inloggning

GitHub #789. Fältet *Invite code*, registreringen genom `AdmitRegistration` och texten *Private beta · Invite only* på inloggningssidan.

**Läs:** [[ADR-0055 Inbjudningskoder och stängd registrering]] § 2, 3 och 8

**Klart när:** utan kod nekas registreringen; en kod ger Pro; en inbjudan i sessionen ger Free; hela testsviten är grön.

**Beror på:** 262

### 264. Privat beta i API:ets registrering

GitHub #790. `POST /api/register` med `voucher_code` och `invitation_token`, under samma regler som webben.

**Läs:** [[ADR-0055 Inbjudningskoder och stängd registrering]] § 3 och 8

**Klart när:** utan kod ger 422 `validation.required`; en ogiltig kod ger `validation.redeemable_voucher`; en inbjudan ger Free; hela testsviten är grön.

**Beror på:** 262, 263

# ADR-0011 Autentisering

**Status:** Antagen 2026-08-03 · Ändrad av [[ADR-0020 Plattformsidentitet och frontendgräns]] på punkten om vilka domäner cookie-läget gäller · Ändrad av [[ADR-0021 Frontendteknik]] på punkten om vad webbfrontenden använder · [[ADR-index]]

## Kontext

En webbfrontend ligger på samma origin som API:et, plus B2B-kunder som vill nå API:et direkt och mobilappar i förlängningen. Klienterna är alltså av två slag: en webbläsare på produktens egen domän, och allt annat.

## Beslut

**Laravel Sanctum i två lägen:**

- **Cookie-läge** för webbklienter som anropar `/api` från webbläsaren. HttpOnly, CSRF-skydd, inga tokens i JavaScript. Frontenden ligger på samma origin som API:et, så cookien är förstaparts utan konstruktioner — se [[ADR-0020 Plattformsidentitet och frontendgräns]]. Med frontendvalet i [[ADR-0021 Frontendteknik]] gör webben inte det: den kör på Laravels sessionsguard med CSRF, och cookie-läget står oanvänt tills en klient behöver det.
- **Personal access tokens** för B2B-integrationer och framtida mobilappar.

**Lösenord som primär inloggning, magic link som alternativ.** TOTP-tvåfaktor tillgängligt.

OAuth2 införs först den dag en tredjepart bygger mot API:et.

## Motivering

Cookie-läget är säkrare för webbfrontenden — en token i `localStorage` är en token som kan stjälas via XSS. Bearer-tokens behövs ändå för det som inte är en webbläsare på egen domän, och det är dem mobilapparna kommer att använda.

Magic links passar användningsmönstret ovanligt bra, men gör e-postleveransen inloggningskritisk. Att ha båda vägarna in betyder att ett leveransproblem hos e-postleverantören inte låser ute alla kunder samtidigt.

TOTP är särskilt viktigt för B2B: ett varv sitter på hundra kunders dokumentation.

Social inloggning valdes bort tills vidare — den sänker registreringströskeln men lägger till beroenden och krånglar till organisationskontona.

## Konsekvenser

- `password_hash` får vara NULL för användare som bara använder magic link.
- E-postverifiering krävs innan en användare kan ta emot delning. Alla som läser något i systemet ska vara identifierade. Se [[ADR-0003 Åtkomstmodell]].
- Magic link-tokens lagras som hash, är engångs, kortlivade och bundna till e-postadressen.
- `last_active_at` uppdateras av **API-anrop från vilken klient som helst**, inte bara inloggning — annars raderar livscykeln i [[ADR-0009 Kvoter och livscykel]] aktiva användare.
- Inloggningsförsök och magic link-utskick måste rate-limitas per adress och per IP.
- B2B-personalkonton loggar in som vanliga användare kopplade till organisationskontot via `account_user`. SSO ligger långt fram och behövs inte för ett varv.

## Alternativ

**Enbart magic links.** Bäst UX för ett sällananvänt system. Valdes bort — gör e-postleveransen till enda vägen in.

**Enbart lösenord.** Enklast. Valdes bort — säsongsmönstret garanterar många glömda lösenord.

**Google- och Apple-inloggning.** Uppskjutet, inte avfärdat.

# M91 · Villkoren och den rättsliga spärren

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-25 och nedbruten samma dag med Tonys svar på frågor ur [[Tankar]]. Efter milstolpen frågar registreringen efter 18 år och villkoren, villkoren säger vad som händer om tjänsten läggs ned och att ett konto som tillhör någon under 18 kan stängas, och en rättslig spärr kan vara tystnadsbelagd. Besluten, alla Tonys 2026-11-25:

- **Nedläggning** ([[ADR-0014 Prismodell]] § Tillägg 2026-11-25). Läggs Mimers ned stängs tjänsten för nya kunder, och gällande prenumerationer får löpa ut innan den stängs. Måste den stängas innan en betald period är slut återbetalas den oanvända delen proportionellt från dag ett. Mimers följer konsumentlagen till punkt och pricka.
- **Spärren i svaret på art. 15** ([[ADR-0095 Integritetsbegäranden]] § 12). Spärren nämns inte bara när en myndighet kräver tystnad. Det kräver en markering när spärren sätts, se [[ADR-0043 Tre loggar]] § Den rättsliga spärren.
- **Villkorsrutan.** Registreringen får en ruta där användaren godkänner villkoren. Rutan bär 18-årsgränsen.
- **Minderåriga.** Mimers har rätt att stänga ett konto som tillhör någon under 18 och radera dess data, och villkoren säger det.

Billing och sidan om vart pengarna går ger ingen issue här: billing väntar på avtalet med Paddle, och sidan byggs inte ([[ADR-0086 Pro, Pro+ och grundarpris]] § Tillägg 2026-11-25).

**Ordningen:** ingen. De tre issuerna beror inte på varandra. Issuerna är skapade i GitHub (#1691–#1693) och sammanfattade här. GitHub-issuerna är bindande.

---

### 499. Rutan för 18 år och villkoren vid registreringen

GitHub #1691. Efteråt har registreringen en ruta där användaren godkänner villkoren och intygar att hon fyllt 18, och tidpunkten sparas.

Rutan kryssas i innan kontot skapas, och registreringen avvisas utan den. `user.terms_accepted_at` (`TIMESTAMP NULL`, [[Konton och åtkomst]] § user) sätts vid registreringen; `NULL` betyder registrerad före rutan. Texten länkar `/terms` och bär 18-årsgränsen.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

### 500. Nedläggning och minderåriga i villkoren

GitHub #1692. Efteråt säger `/terms` och `/refund-policy` vad som händer om Mimers läggs ned, och `/terms` att ett konto som tillhör någon under 18 kan stängas och dess data raderas.

Nedläggningen följer beslutet ovan: stängt för nya kunder, gällande prenumerationer löper ut, och en period som måste avbrytas återbetalas proportionellt från dag ett. Skälet står i [[ADR-0014 Prismodell]] § Tillägg 2026-11-25: en trappa som ger mindre än den proportionella delen gäller inte mot en konsument.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

### 501. Tystnadsbelagd rättslig spärr

GitHub #1693. Efteråt kan en spärr markeras som tystnadsbelagd, och bara en sådan utelämnas ur svaret på art. 15.

`legal_hold.confidential` (`BOOLEAN NOT NULL DEFAULT false`, [[Konton och åtkomst]] § legal_hold). Spärren sätts med `--confidential` eller markeras i efterhand med `legal-hold:confidential`. I appen syns ingen spärr. I svaret på art. 15 tas spärren och dess rader i säkerhetsloggen med, utom när `confidential` är `true`; `reason` lämnas aldrig ut ([[ADR-0095 Integritetsbegäranden]] § 12, [[ADR-0043 Tre loggar]]).

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** —

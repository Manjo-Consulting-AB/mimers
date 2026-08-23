# M2 · Filer

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 16. Uppladdning med innehållshash
`stored_file` och `attachment`. Hash och MIME-typ bestäms **på servern**. Flödets sju steg enligt dokumentet.
**Läs:** [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]]
**Klart när:** ett test visar att en klientskickad hash ignoreras, och att samma innehåll uppladdat två gånger ger en `stored_file` men två `attachment`.
**Beror på:** 13

### 17. Referensräkning och fördröjd radering
Räknaren minskas när en attachment **lämnar papperskorgen**, inte vid soft delete. Fysisk radering tidigast 30 dagar efter att räknaren nått noll.
**Läs:** [[Filer och lagring]] § Radering, [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 16

### 18. Miniatyrer
Genereras vid uppladdning, inte vid visning. Räknas **inte** mot användarens kvot.
**Läs:** [[Filer och lagring]]
**Beror på:** 16

### 19. Säker filleverans
Leveransmetoden är beslutad i [[ADR-0019 Filleverans]]: intern omdirigering med `X-LiteSpeed-Location` mot en katalog under filsubdomänens webbrot, skyddad av en `.htaccess`-regel på `%{ORG_REQ_URI}`.

Mekanismen är **redan verifierad på servern** 2026-08-23 med attrappkod: LiteSpeed följer symlänkar från webbroten, direkt anrop mot `/_protected/` ger 403, och `X-LiteSpeed-Location` levererar filen med 200. Du behöver alltså inte utreda om den fungerar — du ska bygga den.

**Två fällor som verifieringen avslöjade.** LiteSpeed sätter inte `Content-Type` efter filens innehåll vid intern omdirigering; PHP:s standard följer med hela vägen ut. Sätt typen explicit i samma svar som headern. Och `deploy.sh` känner ännu inte till filsubdomänens webbrot — den måste få den som indata, en per miljö: `~/domains/files.mimers.app/public_html` i produktion, `~/domains/files.staging.mimers.app/public_html` på staging. Båda sajterna finns.

Därefter: egen origin för användarfiler, `Content-Disposition: attachment` som standard, behörighetskontroll före leverans. Symlänken och `.htaccess` läggs på plats av `deploy.sh`, inte för hand.
**Läs:** [[ADR-0019 Filleverans]], [[Filer och lagring]] § Säkerhet vid leverans, [[ADR-0007 Fillagring hos inleed]]
**Klart när:** tre test är gröna mot en **utrullad** staging, inte mot en handbyggd katalog — ett direkt anrop mot `/_protected/` ger 403, samma fil levereras via nedladdningsrouten efter behörighetskontroll, och en uppladdad SVG kan inte köra skript i applikationens origin. Dessutom: en nedladdning av en stor fil håller inte en PHP-process upptagen under överföringen.
**Beror på:** 16

### 20. Papperskorg
API för att lista och återställa raderat innehåll, med retention.
**Läs:** [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 17

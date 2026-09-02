# M2 · Filer

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 16. Uppladdning med innehållshash
`stored_file` och `attachment`. Hash och MIME-typ bestäms **på servern**. Flödets sju steg enligt dokumentet.
**Läs:** [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]]
**Klart när:** ett test visar att en klientskickad hash ignoreras, och att samma innehåll uppladdat två gånger ger en `stored_file` men två `attachment`.
**Beror på:** 13
**Byggd som:** 16a tabellerna, lagringen och POST-ytan, 16b bilagelistan och mjukraderingen

### 17. Referensräkning och fördröjd radering
Räknaren minskas när en attachment **lämnar papperskorgen**, inte vid soft delete. Fysisk radering tidigast 30 dagar efter att räknaren nått noll.
**Läs:** [[Filer och lagring]] § Radering, [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 16
**Byggd som:** 17a referensräkningen och markeringen (`purge_after`), 17b det schemalagda jobbet som raderar bytena

### 18. Miniatyrer
Genereras vid uppladdning, inte vid visning. Räknas **inte** mot användarens kvot.
**Läs:** [[Filer och lagring]]
**Beror på:** 16, 17

### 19. Säker filleverans
Leveransmetoden är beslutad i [[ADR-0019 Filleverans]]: intern omdirigering med `X-LiteSpeed-Location` mot en katalog under webbroten, skyddad av en `.htaccess`-regel på `%{ORG_REQ_URI}`.

Mekanismen är **redan verifierad på servern** 2026-08-23 med attrappkod: LiteSpeed följer symlänkar från webbroten, direkt anrop mot `/_protected/` ger 403, och `X-LiteSpeed-Location` levererar filen med 200. Du behöver alltså inte utreda om den fungerar — du ska bygga den.

**Katalogen ligger under appens webbrot, inte under filsubdomänen.** LiteSpeeds interna omdirigering löser URI:n mot den vhost som tog emot anropet, så en rutt på `mimers.app` kan inte leverera ur `files.mimers.app`:s webbrot. Kravet på egen origin är därmed uppskjutet och ersatt av `Content-Disposition: attachment` utan undantag, explicit `Content-Type` och `nosniff`. Hela resonemanget och vägen tillbaka står i [[ADR-0019 Filleverans]] § Uppföljning 2026-08-31.

**Två fällor som verifieringen avslöjade.** LiteSpeed sätter inte `Content-Type` efter filens innehåll vid intern omdirigering; PHP:s standard följer med hela vägen ut. Sätt typen explicit i samma svar som headern. Och `deploy.sh` måste lägga symlänken i releasens `public/` vid varje utrullning — en ny releasekatalog har ingen.

Därefter: `Content-Disposition: attachment` som standard, behörighetskontroll före leverans. Symlänken och `.htaccess` läggs på plats av `deploy.sh`, inte för hand.
**Läs:** [[ADR-0019 Filleverans]], [[Filer och lagring]] § Säkerhet vid leverans, [[ADR-0007 Fillagring hos inleed]]
**Klart när:** ett direkt anrop mot `/_protected/` ger 403 mot en **utrullad** staging, samma fil levereras via nedladdningsrutten efter behörighetskontroll, och en uppladdad SVG levereras alltid som `attachment` med explicit typ. Dessutom: en nedladdning av en stor fil håller inte en PHP-process upptagen under överföringen.
**Beror på:** 16, 18
**Byggd som:** 19a nedladdningsrutten och behörighetskontrollen, 19b `_protected` i utrullningen

### 20. Papperskorg
API för att lista och återställa raderat innehåll, med retention.
Retentionen är 30 dagar, se [[ADR-0008 Soft delete och papperskorg]] § Retentionstiden i MVP.
**Läs:** [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 17
**Byggd som:** 20a lista och återställ innehåll i en container, 20b gallringen när retentionen löpt ut, 20c papperskorg för raderade containers

# ADR-0019 Filleverans

**Status:** Antagen 2026-08-04 · [[ADR-index]]

## Kontext

[[ADR-0007 Fillagring hos inleed]] fastställde att filerna ligger på disk hos inleed, och att inleed inte erbjuder S3. Utan presignerade URL:er måste varje nedladdning passera applikationen för att åtkomstkontrollen ska hålla — men PHP får inte skyffla bytena, eftersom en process då är upptagen så länge överföringen pågår och delad hosting har få processer att ta av.

Inleed kör **LiteSpeed**, som stödjer intern omdirigering via `X-LiteSpeed-Location`. Två frågor behövde besvaras innan mekanismen gick att bygga på.

**Kan filerna ligga utanför webbroten?** Nej. LiteSpeed tar en URI, aldrig en filsökväg:

> Unlike the `X-Sendfile` or `X-Accel-Redirect` implementations in other web servers, LiteSpeed uses a URI instead of a file path for security reasons. In this way, only a file under the document root of a virtual host or a context can be returned.

Officiell workaround är en Apache `Alias` eller en LiteSpeed *Static Context*, båda serverkonfiguration. Inleeds support avböjde 2026-08-04: *"Det är tyvärr så Litespeed är designat."*

**Kan en katalog under webbroten nekas utifrån men ändå levereras internt?** Ja. Samma dokumentation beskriver `%{ORG_REQ_URI}`, en LiteSpeed-specifik rewrite-variabel som håller URI:n från det **ursprungliga** anropet och som inte ändras av en intern omdirigering. Det gör det möjligt att skilja de två fallen åt i en `.htaccess`, utan hjälp från leverantören.

Se [Internal Redirect](https://docs.litespeedtech.com/lsws/redirect/) i LiteSpeeds dokumentation.

## Beslut

**Bytena ligger under filsubdomänens document root, i en katalog som nekas vid direkt anrop och levereras via intern omdirigering.**

```
~/mimers/shared/storage/files/                       bytena, delade mellan releaser
~/domains/files.mimers.app/public_html/
  _protected/  ->  ~/mimers/shared/storage/files/    symlänk, sätts av deploy.sh
    .htaccess                                        nekar direkt åtkomst
```

`.htaccess` i `_protected/`:

```apache
RewriteCond %{ORG_REQ_URI} ^/_protected/
RewriteRule ^_protected/ - [R=403,F]
```

Appens nedladdningsroute, efter behörighetskontroll:

```php
return response()->noContent()->withHeaders([
    'Content-Disposition'  => 'attachment; filename="'.$attachment->filename.'"',
    'X-LiteSpeed-Location' => '/_protected/'.$storedFile->storage_path,
]);
```

Direkt anrop mot `/_protected/ab/cd/…` ger 403, eftersom `ORG_REQ_URI` då **är** den sökvägen. Anrop mot `/files/{ulid}` passerar behörighetskontrollen, och vid den interna omdirigeringen är `ORG_REQ_URI` fortfarande `/files/{ulid}` — regeln matchar inte, och LiteSpeed levererar filen med `sendfile()`.

## Motivering

**Åtkomstkontrollen får inte vila på att sökvägen är svår att gissa.** LiteSpeeds dokumentation föreslår först just det — *"use a hard-to-guess URI"* — och det duger inte här. Sökvägen byggs av innehållshashen, som per definition kan beräknas av var och en som råkar ha samma fil. En användare som laddat upp samma manual kan räkna fram sökvägen till någon annans exemplar. Det är samma resonemang som tvingar hashberäkningen till servern i [[ADR-0006 Innehållsadresserad lagring]]. Rewrite-regeln är därför inte ett extra lager ovanpå obskyritet — den är hela skyddet.

**`ORG_REQ_URI` är rätt variabel just för att den inte följer med.** En regel mot `REQUEST_URI` hade slagit mot båda fallen och gjort katalogen oanvändbar även internt. Att LiteSpeed behåller det ursprungliga anropet genom omdirigeringen är precis den skillnad som behövs.

**Symlänken pekar in i lagringen, inte tvärtom.** Att flytta hela `storage` in i `public_html` vore att ge upp separationen mellan kod, data och webbrot som release-katalogerna i [[Pipeline]] bygger på. Med en symlänk bor bytena kvar i `shared/`, webbroten får bara en pekare, och en utrullning kan inte råka ta med sig kundernas filer.

**Ingen leverantörsberoende konfiguration.** Att lösningen ryms i en `.htaccess` betyder att den fungerar likadant på staging och i produktion, går att versionera i repot, och överlever ett byte av webbhotell inom LiteSpeed-världen. En Static Context hos inleed hade varit renare men osynlig i koden och omöjlig att reproducera lokalt.

## Konsekvenser

- **Filsubdomänen behöver en egen site hos inleed** med egen document root — och **en per miljö**, annars testas staging mot produktionens filer. `files.mimers.app` och `files.staging.mimers.app` finns sedan 2026-08-23. Till skillnad från applikationens webbrot pekas de inte om till releasekatalogen: de behåller en riktig `public_html` och får bara `_protected`-symlänken.
- **`deploy.sh` sätter symlänken och lägger `.htaccess` på plats** som en del av utrullningen. Görs det för hand slutar skyddet fungera vid nästa deploy — och det märks inte, eftersom filerna fortfarande levereras. Testet i issue 19 måste därför köras mot en utrullad miljö, inte mot en handbyggd katalog.
- **Regeln har en tyst felmod.** Försvinner `.htaccess` fungerar nedladdningarna precis som förut, men katalogen är öppen. Lägg ett test som anropar `/_protected/` direkt och kräver 403.
- **Miniatyrerna omfattas av samma skydd.** De visar innehållet och läcker lika mycket som originalet.
- **De tre kraven i [[Filer och lagring]] § Säkerhet vid leverans står kvar** — egen origin, `Content-Disposition: attachment`, behörighetskontroll före leverans. Dokumentationen visar att headern kan sättas i samma svar som omdirigeringen, så de krockar inte.
- **Byte till S3 senare gör hela konstruktionen onödig.** Presignerade URL:er löser detta ur lådan. Ännu ett skäl att hålla Storage-abstraktionen ren, se [[ADR-0007 Fillagring hos inleed]].

## Verifierat på servern

**Följer LiteSpeed symlänkar från webbroten?** **Ja.** Testat 2026-08-23 mot `mimers.app` med en attrapprelease: en symlänk under webbroten ut i `~/mimers/shared/storage/files/` levererade filen med 200. Ingen `Options +FollowSymLinks` behövde sättas. `shared/`-layouten i [[Pipeline]] och rsync-mönstret i [[ADR-0015 Backup]] står därmed fast, och fallbacken nedan behövs inte.

**Håller `ORG_REQ_URI`-regeln?** **Ja.** Samma test:

- direkt anrop mot `/_protected/ab/cd/testfil.txt` gav **403**
- anrop mot en route som satte `X-LiteSpeed-Location: /_protected/ab/cd/testfil.txt` gav **200** med filens innehåll, `Content-Disposition` bevarad från PHP-svaret och `Content-Length` satt av LiteSpeed

Hela mekanismen fungerar alltså end-to-end, med attrappkod och utan hjälp från leverantören. Testrestarna är borttagna; det som står kvar på servern är katalogträden och cronraderna.

**En detalj som kostar en bugg om den glöms:** LiteSpeed sätter **inte** `Content-Type` efter filens innehåll vid intern omdirigering. I testet följde PHP:s standard `text/html; charset=UTF-8` med hela vägen ut, trots att filen var ren text. Appen måste därför sätta `Content-Type` explicit i samma svar som headern. `Content-Disposition: attachment` gör att felet inte blir en säkerhetsbrist, men utan explicit typ får varje nedladdad fil fel typ.

Kvar att verifiera står bara det som kräver en riktig miljö: att `deploy.sh` faktiskt sätter symlänken och `.htaccess` på filsubdomänen vid utrullning. Testet i issue 19 måste därför köras mot en utrullad miljö, inte mot en handbyggd katalog.

## Uppföljning 2026-08-31 — leveransen på appdomänen tills vidare

**Leveransen ligger på appdomänen, inte på `files.mimers.app`, tills vidare.** Nedladdningsrutten `GET /files/{attachment}` (issue 19a) svarar på appdomänen, och `X-LiteSpeed-Location: /_protected/…` löses därmed mot **appens** webbrot — i produktion `~/mimers/current/public/` — inte mot filsubdomänens katalogträd i § Beslut ovan. Koden följer den här uppföljningen, inte trädet: appen sätter alltid `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` och `Content-Type` explicit, så en uppladdad SVG- eller HTML-fil kan inte köra skript i appens domän. Den risk som kravet på egen origin var till för att neutralisera är alltså redan avstängd av svarsheadern.

**Varför appdomänen duger tills vidare:** egen origin skyddar mot att uppladdade filer kör skript i appens domän — och det skyddet uppnås här av `attachment` + `nosniff` i stället för av en annan origin. Egen origin blir nödvändig först när filer ska visas **inline** i webben, och det är issue 61. Att leverera från appdomänen nu innebär inte heller något nytt att verifiera: symlänkstestet 2026-08-23 i § Verifierat på servern kördes redan mot `mimers.app` med en symlänk under appens webbrot.

**Kompenserande krav, bindande så länge leveransen ligger på appdomänen:**

- **Varje leverans är `Content-Disposition: attachment`, utan undantag.** Ingen typbaserad gren och ingen `?inline=1` — inte ens för bilder. Inline-visning är issue 61 och kräver den ADR-revidering som gör egen origin till villkor.
- **`X-Content-Type-Options: nosniff` på varje svar**, tillsammans med en explicit `Content-Type` — LiteSpeed sätter inte typen efter innehållet vid intern omdirigering, se § Verifierat på servern.
- **Samma `.htaccess`-regel på `%{ORG_REQ_URI}` som ovan.** Att katalogen ligger under appens webbrot i stället för filsubdomänens ändrar inte skyddet — regeln skiljer direktanrop från intern omdirigering oavsett vilken vhost som bär katalogen.

**Webbroten 19b ska symlänka `_protected` i är appens, inte filsubdomänens `public_html`.** `deploy.sh` (19b) lägger symlänken och `.htaccess` i den nya releasen:

```
$DIR/public/_protected  ->  $APP/shared/storage/files
```

där `$APP` är `~/mimers` i produktion och `~/mimers-staging` på staging, och `$DIR` är den nya releasekatalogen (`$APP/releases/<RELEASE>`). Appens webbrot `~/domains/mimers.app/public_html` är redan en symlänk till `$APP/current/public` (se [[Pipeline]] § Engångsuppsättning), så en symlänk inne i releasens `public/` är precis vad URI:n `/_protected/…` träffar. Eftersom `deploy.sh` packar upp en ny releasekatalog varje utrullning måste symlänken läggas i varje ny release, inte en gång. En symlänk i filsubdomänens `public_html` hade inte synts av appdomänens vhost, och rutten hade gett 404 i produktion.

**Filsubdomänen finns kvar och är fortfarande slutdestinationen.** Sajterna `files.mimers.app` och `files.staging.mimers.app` är uppsatta sedan 2026-08-23 (§ Konsekvenser) och katalogträdet i § Beslut står sig den dag leveransen flyttar dit — av issue 61 eller av annat skäl. Tills dess pekar trädet på fel webbrot för den kod som ligger i produktion.

## Alternativ

**Hashen som sökväg utan rewrite-skydd.** LiteSpeeds egen förstahandsrekommendation. Valdes bort — hashen är härledbar och åtkomstkontrollen hade varit verkningslös.

**Engångslänk: slumpat namn under webbroten, 302 dit, cron som städar.** I praktiken en presignerad URL byggd av det som finns. Fungerar, men kräver en katalog med löpande skräp, ett cronjobb till, och ett fönster där länken är giltig för den som fått den. Valdes bort eftersom `ORG_REQ_URI` löser samma problem utan rörliga delar. Står kvar här som spår, men behövs inte: symlänkarna verifierades 2026-08-23, se ovan.

**Strömmande PHP-respons.** En process per pågående nedladdning, ur en pool som delas med all annan trafik. Sista utväg, och då med en låg storleksgräns per fil.

**Apache `Alias` eller LiteSpeed Static Context.** LiteSpeeds officiella lösning för filer utanför webbroten. Valdes bort — inleeds support avböjde, och lösningen hade varit osynlig i repot.

**Extern S3 enbart för leveransen.** Löser problemet men flyttar filerna ur den datahemvist som motiverade [[ADR-0007 Fillagring hos inleed]]. Rätt beslut den dagen någon av utlösarna i den ADR:en slår in, fel beslut som lösning på ett leveransproblem.

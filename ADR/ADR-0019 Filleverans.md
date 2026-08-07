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
~/batparmen/shared/storage/files/                      bytena, delade mellan releaser
~/domains/files.yachting.earth/public_html/
  _protected/  ->  ~/batparmen/shared/storage/files/   symlänk, sätts av deploy.sh
    .htaccess                                          nekar direkt åtkomst
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

- **Filsubdomänen behöver en egen site hos inleed** med egen document root. Läggs till miljöfrågorna i [[ADR-0018 Utvecklingsprocess och deploy]].
- **`deploy.sh` sätter symlänken och lägger `.htaccess` på plats** som en del av utrullningen. Görs det för hand slutar skyddet fungera vid nästa deploy — och det märks inte, eftersom filerna fortfarande levereras. Testet i issue 19 måste därför köras mot en utrullad miljö, inte mot en handbyggd katalog.
- **Regeln har en tyst felmod.** Försvinner `.htaccess` fungerar nedladdningarna precis som förut, men katalogen är öppen. Lägg ett test som anropar `/_protected/` direkt och kräver 403.
- **Miniatyrerna omfattas av samma skydd.** De visar innehållet och läcker lika mycket som originalet.
- **De tre kraven i [[Filer och lagring]] § Säkerhet vid leverans står kvar** — egen origin, `Content-Disposition: attachment`, behörighetskontroll före leverans. Dokumentationen visar att headern kan sättas i samma svar som omdirigeringen, så de krockar inte.
- **Byte till S3 senare gör hela konstruktionen onödig.** Presignerade URL:er löser detta ur lådan. Ännu ett skäl att hålla Storage-abstraktionen ren, se [[ADR-0007 Fillagring hos inleed]].

## Kvar att verifiera

**Följer LiteSpeed symlänkar från webbroten?** Kräver `Options +FollowSymLinks`, eller `SymLinksIfOwnerMatch` med rätt ägarskap. Testas på staging så snart miljön finns — lägg en känd fil i `shared/storage/files/` och begär den via en route som sätter headern.

Blir svaret nej måste bytena bo direkt under `public_html`. Beslutet ovan gäller fortfarande, men `shared/`-layouten i [[Pipeline]] och rsync-mönstret i [[ADR-0015 Backup]] behöver då ses över, och den här ADR:en ersättas av en ny.

## Alternativ

**Hashen som sökväg utan rewrite-skydd.** LiteSpeeds egen förstahandsrekommendation. Valdes bort — hashen är härledbar och åtkomstkontrollen hade varit verkningslös.

**Engångslänk: slumpat namn under webbroten, 302 dit, cron som städar.** I praktiken en presignerad URL byggd av det som finns. Fungerar, men kräver en katalog med löpande skräp, ett cronjobb till, och ett fönster där länken är giltig för den som fått den. Valdes bort eftersom `ORG_REQ_URI` löser samma problem utan rörliga delar. **Kvarstår som fallback** om symlänkar visar sig omöjliga och bytena inte kan ligga i webbroten.

**Strömmande PHP-respons.** En process per pågående nedladdning, ur en pool som delas med all annan trafik. Sista utväg, och då med en låg storleksgräns per fil.

**Apache `Alias` eller LiteSpeed Static Context.** LiteSpeeds officiella lösning för filer utanför webbroten. Valdes bort — inleeds support avböjde, och lösningen hade varit osynlig i repot.

**Extern S3 enbart för leveransen.** Löser problemet men flyttar filerna ur den datahemvist som motiverade [[ADR-0007 Fillagring hos inleed]]. Rätt beslut den dagen någon av utlösarna i den ADR:en slår in, fel beslut som lösning på ett leveransproblem.

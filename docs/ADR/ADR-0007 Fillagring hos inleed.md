# ADR-0007 Fillagring hos inleed

**Status:** Antagen 2026-08-03 · öppen fråga besvarad 2026-08-04 · [[ADR-index]]

## Kontext

Upp till 200 GB finns tillgängligt hos inleed initialt, med servrar i **Sverige och Frankrike**. Alternativet var S3-kompatibel objektlagring hos Hetzner, Scaleway, Cloudflare R2 eller Backblaze B2.

## Beslut

Filerna ligger **hos inleed**. All filhantering går genom **Laravels Storage-abstraktion** — applikationskoden rör aldrig filsystemet direkt.

## Motivering

Datahemvist i EU hos en leverantör som redan används är både enklast och ett verkligt säljargument. Den som lägger hela sin båtdokumentation någonstans bryr sig om var den hamnar, och "servrar i Sverige och Frankrike" är ett rakare besked än "EU-region hos ett amerikanskt bolag".

Storage-abstraktionen gör ett framtida byte till objektlagring till en konfigurationsändring istället för en refaktorering. Den kostar ingenting att använda från början och allt att lägga till efteråt.

## Konsekvenser

**Utan presignerade URL:er måste nedladdningar gå via appen** för att åtkomstkontrollen ska hålla. Låt inte PHP skyffla bytena — LiteSpeed stödjer intern omdirigering i stil med X-Sendfile: appen kontrollerar behörigheten och svarar med en header, webbservern levererar filen.

Tre säkerhetskrav som följer av att filerna serveras från egen infrastruktur:

1. **Egen origin för användarfiler**, `files.mimers.app`. En uppladdad SVG eller HTML-fil kör annars skript i appens domän.
2. **`Content-Disposition: attachment`** som standard.
3. Åtkomstkontroll före varje leverans, ingen gissningsbar sökväg.

Backupen blir helt och hållet eget ansvar, och den får inte ligga hos inleed. Se [[ADR-0015 Backup]].

Virusskanning är osannolik på delad hosting. `scan_status` finns i schemat men sätts till `skipped` i MVP; de tre kraven ovan är det faktiska skyddet. Se [[Filer och lagring]].

## Besvarad fråga: ingen S3 hos inleed

Inleed svarade 2026-08-04: *"Vi har ingen egen S3-lagring, men det går från våra tjänster att ansluta mot andra S3-lagring såklart."*

Alltså **ren disk**. Beslutet ovan står fast, men två saker följer:

**Presignerade URL:er finns inte som möjlighet.** Varje nedladdning måste passera applikationen. Det gör intern omdirigering till den bärande mekanismen, inte en optimering — utan den binder varje pågående nedladdning en PHP-process så länge överföringen pågår, och delad hosting har få processer att ta av. En handfull användare som laddar ner stora manualer samtidigt kan då göra hela siten otillgänglig.

**Vägen till objektlagring är öppen men går till en tredje part.** Att inleed tillåter anslutning mot extern S3 ändrar ingenting idag — alternativen och skälen att välja bort dem står kvar nedan. Det som förändras är att bytet blir en konfigurationsändring i Storage-abstraktionen den dag något av följande inträffar:

- lagringen närmar sig **200 GB**
- utgående trafik slår i webbhotellets fair use-gräns
- nedladdningar visar sig belasta processpoolen trots intern omdirigering

Sker det: välj i första hand en leverantör som bevarar hemvistargumentet. Safespring och Elastx i Sverige, Scaleway i Paris, Hetzner i Tyskland och Finland. Cloudflare R2 och Backblaze B2 är billigare men amerikanska, och undergräver då säljargumentet som motiverade hela beslutet.

## Leveransen bröts ut

Inleed kör **LiteSpeed**, vilket ger intern omdirigering via `X-LiteSpeed-Location`. Men LiteSpeed accepterar bara en URI under document root, aldrig en filsökväg — och det räcker för att göra leveransen till ett eget beslut med egna alternativ.

Se [[ADR-0019 Filleverans]].

## Alternativ

**Hetzner eller Scaleway.** EU-ägda bolag, presignerade URL:er, versionering och livscykelregler ur lådan. Valdes bort — inleed ger samma datahemvist utan ytterligare en leverantörsrelation.

**Cloudflare R2 eller Backblaze B2.** Billigare vid volym, R2 utan egress-avgift. Valdes bort — amerikanska bolag, vilket underminerar hemvistargumentet.

# ADR-0014 Prismodell

**Status:** Antagen 2026-08-03 · Pro-filstorleken sänkt till 64 MB 2026-09-04 efter serverns tak · Konsumentnivåerna och ägarbytets bonus ersatta av [[ADR-0086 Pro, Pro+ och grundarpris]] 2026-11-18; B2B-delen gäller · Merchant of record är Paddle, tillägg 2026-11-24 · Billing och nedläggning, tillägg 2026-11-25 · B2B-spåren blir roller, tillägg 2026-11-27 · [[ADR-index]]

## Kontext

Ursprungstanken var en gratisversion med hela systemet fast begränsat: bilagor under 1 MB, max tre per item, en inbjuden användare, 500 MB tak — och Pro med 10 GB för omkring 10 €/år.

Evernotes gratisnivå (50 anteckningar, en notebook) och deras Starter på 79 €/år användes som jämförelse.

## Beslut

**Gratis:** en container, 1 GB, 10 MB per fil, en delad användare. Påminnelser, ICS och export ingår.

**Pro, 39–49 €/år:** obegränsat antal containers, obegränsad delning, 25 GB, 64 MB per fil, utskrivbar PDF-pärm, ägarbyte, webhooks, utlåningspåminnelser.

**Mottagaren vid ägarbyte får tolv månader Pro.**

**Pro positioneras som *ett konto för allt du äger*, inte som *obegränsat antal containers*.** Argumentet är samlad tidslinje, en påminnelseström och total ägandekostnad över alla objekt.

**B2B (efter MVP):** mäklare omkring 490 €/år, varv 5–8 € per båt och år i nivåer (490 / 990 / 1990 €), charter 15–25 € per båt och år.

Betalning via **merchant of record** — Paddle eller Lemon Squeezy.

## Motivering

**Gränserna flyttades från filstorlek till antal containers och totalt utrymme.** En mobilbild är 3–5 MB och en manual ofta 10 — begränsningar på 1 MB och tre bilagor slår till på dag ett. Användaren möter väggen innan hon förstått vad produkten är bra för, och upplever det inte som "här borde jag uppgradera" utan som "det här funkar inte". Produktens dragningskraft är ackumulerad data, och den känslan uppstår efter månader. Gratisnivån måste tillåta ackumulering.

**Påminnelser och export är medvetet fria.** Påminnelserna skapar vanan som gör att någon återvänder; strypta blir kontot bortglömt istället för uppgraderat. Export skapar förtroende och krävs ändå enligt GDPR.

**Pro-filstorleken är 64 MB för att det är vad servern klarar.** `upload_max_filesize` hos inleed står på 64M, satt i `public/.htaccess` och speglad i `config/files.php` — se [[Pipeline]] § Uppladdningsgränser. Ett annonserat tak på 100 MB hade varit ett löfte plattformen bryter vid uppladdning nummer ett, och en gräns som slår i som ett HTTP-fel istället för som ett förklarat kvotmeddelande är den sämsta sortens gräns. Talet är alltså en följd av hostingen, inte av prissättningen: byter vi plattform får det justeras om kunderna efterfrågar det. 64 MB rymmer fortfarande en skannad manual eller en videosekvens från telefonen, så gränsen kostar inget i praktiken idag.

**Priset höjdes från 10 €.** Marginalen på 10 € är god — särskilt med dedup, där femhundra användare med samma manual kostar en manual — men priset måste bära supporten. Mejl från 5 % av kunderna en gång om året äter upp årsavgiften.

**"Billigare än Evernote" är fel utgångspunkt.** Kunden väljer inte mellan Evernote och den här produkten, utan mellan en skokartong med kvitton och ett system byggt för ändamålet. Nischade produkter prissätts nästan alltid över de generella verktyg de ersätter, eftersom passformen är värdet. Evernote är dessutom ett dåligt ankare: deras gratisnivå är en demo, och de är illa omtyckta för sina prishöjningar. Relevantare jämförelser är Navionics på 25–60 €/år eller en impellerservice som blev av för sent. Men 79 € är ett verkligt tak i marknaden, och 39–49 € ligger tryggt under.

**Tolv månader Pro till mottagaren** gör båtaffären till kundanskaffningskanal. Säljaren betalar redan, köparen ärver en välfylld pärm hon inte vill förlora, och efter ett år har hon egen historik i den. Kostnaden är en PDF och lite lagring.

**Positioneringen är det billigaste motdraget mot flera gratiskonton.** Tekniskt hindrar ingenting någon från att skapa ett nytt gratiskonto per objekt — e-postadresser är oändliga, och varje spärr vid registrering kostar fler äkta användare än den stoppar. Men säljs Pro som *obegränsat antal containers* är multikonto en exakt substitut för produkten. Säljs det som ett samlat konto är splittringen något användaren avstår från själv: hon förlorar den gemensamma vyn, får påminnelserna spridda över flera adresser och kan aldrig se vad allt hon äger kostar tillsammans. Det som ska hindra beteendet är alltså vad Pro *är*, inte en kontroll. Se [[ADR-0017 Missbruksvektorer]].

**Mäklarpriset är medvetet lågt** — varje överlämning föder en ny konsumentanvändare. Mäklaren är distribution, inte intäkt.

**Charter betalar mest** eftersom värdet är operativt: en båt som står stilla en bokad vecka kostar dem mer än årsavgiften.

Merchant of record valdes eftersom digitala tjänster till privatpersoner i EU ska momsredovisas i köparens land. Paddle och Lemon Squeezy blir säljare gentemot kunden och hanterar det, mot några procent mer än Stripe. För en ensam utvecklare är det nästan alltid värt det. *Detta är inte skatterådgivning — stäm av med redovisningskonsult.*

## Konsekvenser

- Gränserna ligger i `plan.limits` som JSON, så ett nytt B2B-erbjudande blir en ny rad, inte ny kod. Se [[Planer och kvoter]].
- Rättighetslagret byggs i MVP; betalflödet kopplas på senare.
- B2B-priset bärs av personalroller, white-label, massoperationer, revisionslogg och API-access — inte av kvot. Utan de delarna säljs bara utrymme, och då pressas priset.
- **Pro-filstorleken är bunden till hostingen.** Höjs `upload_max_filesize` — ny plattform eller PHP Selector — ska `max_file_bytes` i planen, `config/files.php` och `public/.htaccess` flyttas tillsammans. Sänks taket någon gång utan att planen följer med annonserar vi något servern inte kan leverera.
- **B2B-siffrorna är resonerade uppskattningar, inte marknadsdata.** Validera med två–tre varv som designpartners innan något byggs.

## Alternativ

**10 €/år med 10 GB.** Ursprungsförslaget. Valdes bort — bär inte supportkostnaden.

**Begränsa filstorlek och antal bilagor.** Valdes bort — hindrar ackumuleringen som skapar betalningsviljan.

**Begränsa påminnelser på gratisnivån.** Valdes bort — sänker retentionen mer än det driver konvertering.

## Tillägg 2026-11-24

*Tony 2026-11-24, i #1600:* **Merchant of record är Paddle**, inte Lemon Squeezy. Valet i § Beslut (*Paddle eller Lemon Squeezy*) är därmed avgjort, och Lemon Squeezy är inte längre ett alternativ.

**Köpet är ett avtal mellan köparen och Paddle**, på Paddles köparvillkor. Paddle står därför i policyns § 7 som merchant of record och inte som underbiträde, och finns inte i registret på `/subprocessors` ([[M86 Integritetspolicyn och underbiträdena]]).

**Återbetalning inom 30 dagar** sker enligt `https://manjo.me/refund-policy.php`.

*Tony 2026-11-25:* Mimers får en egen återbetalningspolicy på `/refund-policy` ([[M86 Integritetspolicyn och underbiträdena]] issue 494). Sidan på `manjo.me` är Mail Shields. Garantin är densamma, 30 dagar från varje betalning utan skäl, och gäller även förnyelser och grundarpriset. Kontaktvägen är formuläret på `/contact`.

Paddle finns inte i koden än. Policyn publiceras med § 7 som den är skriven eftersom betalflödet är på väg in; se § Tillägg 2026-11-25. Den öppna frågan i [[ADR-0086 Pro, Pro+ och grundarpris]] § Konsekvenser om *Paddle eller Lemon Squeezy* pekar hit.

## Tillägg 2026-11-25

*Tony 2026-11-25:* sex frågor ur [[Tankar]] är avgjorda. Två av dem gäller den här ADR:en.

**1. Billing väntar på avtalet med Paddle.** Allt som gäller billing väntar tills avtalet är signerat. Då pekar **Manage billing** på kundens portal hos Paddle, och Mimers bygger ingen egen vy till en början. `/privacy` publiceras med § 7 som den är skriven: ingen kan betala innan flödet finns, och texterna säger redan *or through Paddle*. Knappen byggs när Paddle kopplas på, och ingen backlog-issue skrivs förrän avtalet är signerat.

**2. Nedläggning.** Läggs Mimers ned stängs tjänsten för nya kunder, och gällande prenumerationer får löpa ut innan den stängs. Måste den stängas innan en betald period är slut återbetalas den oanvända delen **proportionellt från dag ett**. Skälet: en trappa som ger mindre än den proportionella delen gäller inte mot en konsument, och Mimers följer konsumentlagen till punkt och pricka. Byggs som issue 500 (#1692) i [[M91 Villkoren och den rättsliga spärren]].

## Tillägg 2026-11-27

*Tony 2026-11-27:* B2B-spåren byter namn och blir allmänna roller, enligt [[ADR-0033 Produktens omfång]] § Tillägg 2026-11-27: segmenten blir roller. Beslutets rad om B2B (*mäklare, varv, charter*) läses med de nya namnen:

- **Förmedlare**, tidigare mäklare: omkring 490 €/år.
- **Förvaltare**, tidigare varv: 5–8 € per förvaltad container och år, i nivåerna 490 / 990 / 1990 €. Förvaltaren äger inte containern utan får delegerad åtkomst.
- **Flottägare**, tidigare charter: 15–25 € per container och år.
- **Byggare eller leverantör**, tidigare nybyggnadsvarv: överlämnar vid leverans.

Siffrorna ändras inte och är fortfarande resonerade uppskattningar. Konsekvenspunkten om att validera med *två–tre varv som designpartners* ska läsas som *två–tre B2B-kunder*.

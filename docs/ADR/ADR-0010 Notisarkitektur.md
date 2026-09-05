# ADR-0010 Notisarkitektur

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Påminnelser är produktens retentionsfunktion — det är de som får någon att återvända till en pärm hon annars öppnar två gånger om året. Frågan var vilka kanaler som ska stödjas: enbart e-post, eller även webhooks, Telegram och annat.

## Beslut

En **notiskärna med utbytbara kanaler**. Notisen skapas som en rad, kön levererar. Aldrig skickad synkront i ett request.

Tre utgångar i MVP: **e-post**, **ICS-kalenderfeed**, **webhooks**.

Telegram byggs inte som förstapartsintegration.

## Motivering

**E-post** är obligatorisk men leveransen är underskattad. Transaktionsmejl från delad hosting hamnar i skräpposten, och en påminnelseprodukt vars påminnelser inte syns är värdelös. Därför en riktig leverantör — ~~Postmark~~ → **Mailgun**, se uppföljningen nedan — med SPF, DKIM och DMARC på `mimers.app`. Några timmars arbete som avgör om produkten fungerar.

**Uppföljning 2026-09-05: leverantören byts från Postmark till Mailgun.** Skälet är villkoren, inte funktionen: båda är transaktionsleverantörer med API-transport i Symfony Mailer, domänverifiering med SPF och DKIM, och en webhook som rapporterar studsar och spamanmälningar tillbaka. Ingenting i beslutet ovan hänger på vilken av dem det är — motiveringen är *"en leverantör med eget sändarrykte i stället för delad hosting"*, och den håller oförändrad. Det som byts är namnet, transportpaketet, miljövariablerna och webhookens form.

**Webhookens autentisering är den enda sakliga skillnaden.** Postmark lade användarnamn och lösenord i webhook-URL:en och skickade dem som HTTP Basic; Mailgun signerar i stället varje anrop med HMAC-SHA256 över `timestamp` + `token`, med kontots webhook-signeringsnyckel som nyckel. Det är en starkare mekanism — hemligheten går aldrig över tråden — men den kräver också att mottagaren skyddar mot återuppspelning, eftersom en avlyssnad signatur annars går att skicka om. Kravet från ADR:ns § Konsekvenser (*"studsar och spamanmälningar matas tillbaka in i systemet"*) är oförändrat; vad koden ska verifiera är det inte.

**En återkallad undertryckning har ingen motsvarighet hos Mailgun.** Postmark skickade `SubscriptionChange` med `SuppressSending: false` när någon återaktiverade en adress i gränssnittet, och issue 33b byggde en väg tillbaka på den händelsen. Mailgun för sina spärrlistor själv och skickar ingen webhook när en rad tas bort ur dem. Vägen tillbaka för en adress som undertryckts av misstag blir därför manuell — ta bort raden i `email_suppression` och i Mailguns egen lista — tills något efterfrågar mer. Det är en försämring, den enda i bytet, och den är liten: den rör en händelse som inträffar när en människa redan sitter i ett gränssnitt.

**ICS-feeden** är den underskattade vinnaren. En hemlig prenumerationslänk som Apple Calendar eller Google Calendar hämtar själv. En läsendpoint som genererar en textfil: nästan gratis att bygga, ingen leveransproblematik, inget spamfilter, fungerar på varje enhet. För en produkt som i grunden handlar om underhållsschema ger den mer verkligt värde än push.

**Webhooks** gör resten överflödigt. Systemet är API-först, och B2B-kunderna vill ha händelserna i sina egna system. Dessutom löser en signerad POST både Telegram, Slack, Discord och Home Assistant utan att en enda integration behöver underhållas — den som vill kopplar själv via n8n eller Zapier.

**Telegram** valdes bort medvetet. Bot-API:et är trivialt, vilket gör det frestande, men målgruppen är båtägare med låg Telegram-penetration i Norden. Det är en funktion man bygger för att det är roligt, inte för att någon efterfrågar den.

## Konsekvenser

Fyra krav som är sura att lägga till i efterhand och därför byggs direkt:

1. **Outbox med leveransstatus per kanal.** Minutcronen dubbelskickar annars förr eller senare. `dedupe_key` och unik `(notification_id, channel)`.
2. **Tysta timmar och tidszon per användare.** Ingen vill ha mejl om impellern klockan tre på natten, och seglare befinner sig sällan i sin hemtidszon.
3. **Veckosammanfattning som standard.** I april förfaller allting samtidigt; tjugo separata mejl på en förmiddag ger en avprenumeration.
4. **Studsar och spamanmälningar matas tillbaka in i systemet** och kopplas till inaktivitetslogiken i [[ADR-0009 Kvoter och livscykel]].

Notisens `payload` innehåller data, aldrig färdig text — den renderas per kanal och språk vid leverans. Se [[ADR-0013 Språk och i18n]].

Utgående webhook-URL:er måste valideras mot SSRF vid både registrering och leverans, eftersom DNS kan ändras däremellan.

**Leveransen körs in-process, inte som subprocesser.** `proc_open` är avstängt hos inleed, verifierat 2026-08-23 — se [[ADR-0018 Utvecklingsprocess och deploy]]. Det betyder att schemaläggaren måste uttrycka outboxens arbete som `->call(...)` eller `->job(...)`, aldrig `->command(...)`, och att kön dras med `queue:work` och inte `queue:listen`. Minutcronen räcker fortfarande, men allt arbete sker i den enda process cronraden startar. En långsam leverans blockerar alltså de övriga under samma minut — ett skäl till att ge varje utgående anrop en snäv timeout och låta outboxen försöka igen nästa minut, hellre än att vänta ut en död mottagare.

Web push ligger efter MVP.

## Alternativ

**Enbart e-post.** Enklast. Valdes bort — ICS kostar nästan ingenting och webhooks låser upp hela B2B-värdet.

**Förstapartsintegrationer mot Telegram och Slack.** Valdes bort — varje integration är underhåll i all framtid, och webhooks täcker behovet.

# ADR-0096 Kontaktformuläret

**Status:** Antagen 2026-11-24 · Bygger vidare på [[ADR-0010 Notisarkitektur]], [[ADR-0017 Missbruksvektorer]] och [[ADR-0095 Integritetsbegäranden]] § 2 och § 6 · [[ADR-index]]

Tonys beslut 2026-11-24 om hur användare och utomstående når supporten. Kodarbetet ligger i [[M89 Kontaktformuläret]].

## Kontext

Mimers har ingen publicerad e-postadress. `/about` (#1617) och `/privacy` (#1600) hänvisar till ett kontaktformulär på `/contact`, men sidan finns inte. Det var en öppen fråga i [[Tankar]] vad formuläret ska fråga efter, vart meddelandet ska gå och vilket skydd det ska ha mot skräp.

Två alternativ vägdes mot varandra. I det ena lagras ärendet i en tabell tills det är klart och gallras efter tolv månader. I det andra paketeras meddelandet och skickas med mejl. Brevlådan `support@mimers.app` finns sedan 2026-11-24 hos inleed, som redan är vårt biträde. En tabell bredvid den hade bara blivit en kopia av det som redan står i brevlådan. Det finns ingen adminroll i dag, och därför ingen yta i appen där personalen kan läsa ärendena. Rollen behövs troligen senare men inte nu ([[Tankar]] § En adminsida).

## Beslut

### 1. `/contact` är en sida för alla

Sidan ligger utanför `auth` och `guest`, som informationssidorna. Formuläret har en kategori och ett meddelande.

- **Inloggad:** namnet och e-postadressen hämtas från `user`. De visas men kan inte ändras i formuläret, och servern läser dem från sessionen, aldrig från begäran.
- **Utloggad:** namnet och e-postadressen fylls i och är obligatoriska. Uppgifterna är **overifierade**.

Sidfoten och inloggningssidan får länken *Contact* bredvid de juridiska länkarna.

### 2. Kategorierna

Tio kategorier, med nycklar på engelska. Etiketterna och hjälptexterna står i `lang/`:

| Nyckel | Etikett |
|---|---|
| `general` | General Inquiry |
| `technical` | Technical Support |
| `account` | Account & Access |
| `billing` | Billing & Subscription |
| `privacy` | Data & Privacy |
| `features` | Features & Suggestions |
| `sharing` | Sharing & Permissions |
| `agents` | AI Agents |
| `business` | Business & Partnerships |
| `other` | Other |

Listan finns på ett ställe i koden. Valideringen, sidan och mejlet läser den därifrån.

### 3. Meddelandet går med mejl till supportadressen

Formuläret lagrar ingenting i databasen. Meddelandet skickas genom Mailgun till `config('support.address')`, som har förvalet `support@mimers.app`.

- **Avsändaren är appens egen**, `MAIL_FROM_ADDRESS`. Personens adress står bara i `Reply-To`. En främmande adress i `From` underkänns av DMARC och hamnar i skräpposten.
- **Ämnet** är `[Contact] <etikett> — <namn>`. Utanför produktion får ämnet prefixet `[<miljö>]`, så att ett mejl från staging aldrig förväxlas med ett riktigt ärende.
- **Kroppen är vanlig text** med kategori, namn, e-post, om personen var inloggad, personens och kontots ULID när hon var det, språk, tidpunkt i UTC och meddelandet. Den har ingen IP-adress och ingen webbläsarsträng. Utloggade ärenden märks *Not signed in — identity not verified*.
- **Personalen svarar från brevlådan.** Konversationen förs där och inte i appen.

### 4. Skickas medan personen väntar, inte genom kön

Mejlet skickas i samma begäran som formuläret. Vägrar Mailgun får personen ett fel, och det hon skrivit står kvar i formuläret. Det finns ingen tabell att falla tillbaka på, så ett köjobb som misslyckas i det tysta hade tappat meddelandet. Misslyckandet loggas på felnivå, utan meddelandets text, så att det syns i övervakningen ([[ADR-0079 Övervakning]]).

### 5. Inget kvitto till avsändaren

Sidan visar en bekräftelse. Inget mejl går till den adress som personen angett. Ett kvitto till en overifierad adress hade låtit vem som helst skicka text från `mimers.app` till en främling, med samma resonemang som i [[ADR-0017 Missbruksvektorer]] om låntagaren.

### 6. Skyddet mot skräp

- **Honeypot:** ett dolt fält som ska vara tomt. En träff ger samma bekräftelse som ett riktigt ärende, men inget mejl skickas.
- **Tidsfälla:** ett formulär som skickas mindre än tre sekunder efter att sidan ritades behandlas som en honeypot-träff. Tidpunkten bärs i ett signerat fält och inte i sessionen.
- **Begränsare** `throttle:contact`: fem i timmen per IP för en gäst och tio i timmen per person för en inloggad.
- Ingen Turnstile eller captcha, eftersom det hade gett ett nytt biträde.

### 7. Data & Privacy öppnar även ett integritetsärende

En inskickning i kategorin `privacy` anropar `OpenPrivacyRequest` med typen `other`, namnet, e-postadressen och meddelandet, och `user_id` när personen är inloggad. Ärendets referens står i mejlets ämne. Mejlet är den notis till personalen som [[ADR-0095 Integritetsbegäranden]] § 6 utlovar.

Varje inskickning i kategorin blir ett ärende, även en vanlig fråga. Handläggaren stänger den med `closed`. Det är billigare att registrera för många än att missa en begäran enligt art. 15–21 för att personen valde fel kategori. Ärendet följer ADR-0095 och gallras efter 36 månader, inte efter tolv.

### 8. Supportadressen spärras aldrig

Mailguns webhook skriver aldrig `support.address` till `email_suppression`. En studs eller en spamanmälan för den adressen loggas i stället på felnivå. En full brevlåda eller ett kort avbrott hos inleed får inte stänga formuläret för gott. Mailgun för också en egen spärrlista som appen inte styr. Loggraden är signalen att den behöver rensas för hand ([[ADR-0010 Notisarkitektur]] § uppföljningen 2026-09-05).

### 9. Gallringen är en rutin i brevlådan

Ett supportärende raderas ur brevlådan tolv månader efter det sista meddelandet i tråden. Det görs för hand i brevlådan, inte av appen, eftersom appen inte har något att gallra. Integritetsärenden gallras enligt § 7.

## Konsekvenser

- **Förutsättning: Mailgun måste vara verifierat i DNS.** En kontroll 2026-11-24 visade att `mimers.app` har MX till `mail.mimers.app`, SPF för inleed och en DKIM-nyckel. `mg.mimers.app` och `mg-staging.mimers.app`, som är `MAILGUN_DOMAIN` enligt [[Pipeline]], hade däremot varken SPF, DKIM eller MX. Utan dem levereras inget mejl från appen pålitligt, och det gäller inte bara formuläret. Det är DNS-arbete hos inleed och inget som en issue kan göra.
- `/about` och `/privacy` får en sida att peka på. Tankar-frågan om `/privacy` kan publiceras före `/contact` krymper till betalflödet.
- [[Registerförteckning]] får raden *Supportärenden*, och raden för inleed omfattar även brevlådan.
- `/privacy` säger i dag inget om hur länge ett supportärende sparas. Texten är godkänd copy (#1600), och en mening om tolv månader är Tonys att lägga till.
- **När adminrollen kommer** kan formuläret skriva till en tabell i stället för, eller utöver, mejlet. Sidan, kategorierna och skyddet ändras inte av det.
- Formuläret tar inga bilagor. En skärmdump för Technical Support hade dragit med sig hela fil- och kvotkedjan. Personalen ber om den i svaret.

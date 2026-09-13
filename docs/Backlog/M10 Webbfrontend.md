# M10 · Webbfrontend

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-08-22. Se [[ADR-0021 Frontendteknik]] — Inertia med Vue 3 och Tailwind i samma Laravel-app, sessionsguard för webben, ingen affärslogik i Inertia-controllers.

**Körs parallellt med M1–M3, inte efter M9.** Numreringen är sekventiell men beroendena är det som gäller: varje issue nedan väntar bara på sin egen backenddel. Att bygga vyerna löpande är det enda sättet att upptäcka att en endpoint saknar ett fält innan hela API:et är fryst.

Gemensamt för alla issues i milstolpen: text formuleras på servern ur `lang/`, aldrig i JavaScript. Behörighetskontroller görs i policies, aldrig genom att dölja en knapp.

### 51. Frontendskal
Inertia-rotvy, layoutkomponent, navigation, Tailwind-uppsättning, felhanterings- och flashmeddelandemönster. Delade props: inloggad användare, kontots plan, aktiv container. Ett dokumenterat mönster för formulär med validering från FormRequests, och för att rendera props **ur samma API Resource-klasser som `/api`** — båda mönstren används av alla efterföljande issues.
**Läs:** [[ADR-0021 Frontendteknik]]
**Klart när:** en skyddad exempelvy renderar, ett formulär visar valideringsfel från servern, och `php artisan view:cache` fungerar med Inertias rotvy.
**Beror på:** 1

### 52. Språk i frontenden
Svenska och engelska. Språket kommer från användarens `locale`, i andra hand kontots — aldrig från `Accept-Language`. Strängar levereras som delade props ur `lang/`.
**Läs:** [[ADR-0013 Språk och i18n]], [[ADR-0021 Frontendteknik]]
**Klart när:** en engelsktalande medlem i ett svenskt konto får engelska vyer, och ingen användarvänd sträng är hårdkodad i en Vue-komponent.
**Beror på:** 51, 3

### 53. Inloggnings- och kontovyer
Registrering, inloggning, utloggning, e-postverifiering, magic link, TOTP-aktivering och återställningskoder. Kontoinställningar: locale, timezone, unit_system.
**Läs:** [[ADR-0011 Autentisering]], [[Konton och åtkomst]]
**Klart när:** hela vägen in fungerar i webbläsaren för alla tre inloggningssätten, och rate limiting ger ett begripligt meddelande i stället för ett tomt fel.
**Beror på:** 52, 4, 5, 6
**Byggd som:** 53a vägen in (registrering, inloggning, magic link, e-postverifiering), 53b tvåfaktorns aktivering och återställningskoderna, 53c kontoinställningarna — profilen, kontot och `AccountPolicy::update()`

### 54. Containervyer
Lista, skapa, redigera, välja aktiv container. `kind` styr presentation, inte logik.
**Läs:** [[Konton och åtkomst]], [[ADR-0002 Konto äger container]]
**Beror på:** 53, 8

### 55. Delning och inbjudningar
Bjuda in, se och återkalla utestående inbjudningar, acceptflödet för mottagaren. Åtkomstformerna presenteras med sina faktiska konsekvenser — ett varv som får `managed` ska se att det inte äger pärmen.

**Fyra nivåer, två synliga.** [[ADR-0028 Åtkomst på itemnivå]] ersatte R/RW med ladder `read` < `create` < `write` < `delete`. Visa `read` och `write` som standard; `create` och `delete` hör hemma bakom "avancerat". Fyra val är för mycket för en ägare som bara delar med sambon. Här bor också delningen av **enskilda items**: ägaren måste se hur många items en grant faktiskt når, så att "motorn" inte tyst betyder fyra items.
**Läs:** [[ADR-0003 Åtkomstmodell]], [[ADR-0028 Åtkomst på itemnivå]], [[Konton och åtkomst]] § invitation
**Beror på:** 54, 9, 10, 72

### 56. Kategorier och taggar
CRUD för båda. **Färdiga kategoriuppsättningar** per språk och containertyp bor här, som frontenddata — API:et får aldrig veta vad orden betyder.
**Läs:** [[ADR-0004 Fria taggar och kategorier]], [[Items och organisation]]
**Klart när:** ett nyskapat konto erbjuds en uppsättning på sitt språk vid registrering och kan tacka nej utan att fastna.
**Beror på:** 54, 11, 12

### 57. Itemvyer
Lista med miniatyrer, detaljvy, skapa och redigera. Kategori, taggar, fritext.
Ett item användaren bara har `read` på visas utan redigeringsytor, inte med knappar som ger felkod.
**Läs:** [[Items och organisation]], [[ADR-0028 Åtkomst på itemnivå]]
**Beror på:** 56, 13, 71

### 58. Relationer mellan items
Koppla ihop items och navigera relationerna från detaljvyn.
En länk till ett item utanför användarens omfång visas inte alls — inte som ett namnlöst spöke.
**Läs:** [[Items och organisation]] § item_link, [[ADR-0028 Åtkomst på itemnivå]]
**Beror på:** 57, 14, 71

### 59. Sök och filter
Fritextsök plus filtrering på kategori, tagg och container. Tomt resultat säger vad som filtrerades bort.
"Tomt resultat säger vad som filtrerades bort" gäller **användarens egna filter** — aldrig att träffar dolts av behörighet, se issue 73.
**Läs:** [[ADR-0012 Sök]], [[Items och organisation]], [[ADR-0028 Åtkomst på itemnivå]]
**Beror på:** 57, 15, 73

### 60. Uppladdning
Drag-drop, flera filer samtidigt, framdrift per fil, miniatyrer när de finns. Kvotfel visas som gräns och värde, inte som ett rått felmeddelande.
**Läs:** [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]]
**Klart när:** en avbruten uppladdning lämnar inget halvt tillstånd i vyn, och en kvotöverskridning förklaras med vilken gräns som slog i.
**Beror på:** 57, 16, 18

### 61. Filvisning och nedladdning
Visning av bilder och PDF:er, nedladdningslänkar mot filoriginet.
**Läs:** [[ADR-0019 Filleverans]]
**Klart när:** användarfiler serveras från `files.mimers.app` och inget innehåll därifrån kan köra skript i appens origin.
**Beror på:** 60, 19

### 62. Papperskorg
Lista raderat innehåll, återställ, se hur lång tid som återstår.
Papperskorgen visar bara det användaren själv kunde se innan det raderades, se issue 74.
**Läs:** [[ADR-0008 Soft delete och papperskorg]], [[ADR-0028 Åtkomst på itemnivå]]
**Beror på:** 57, 20, 74

### 63. Scheman och uppgifter
Skapa scheman på item, se förekomster, bocka av, hantera beroenden. Försenat visas som härlett tillstånd.
**Läs:** [[Scheman och uppgifter]], [[ADR-0005 Schema och förekomst]]
**Beror på:** 57, 21, 22, 23

### 64. Todo-vyn
Startsidan efter inloggning: förekomster över alla åtkomliga containers, filtrerad enligt dokumentet.
**Läs:** [[Scheman och uppgifter]] § Todo-listan
**Beror på:** 63, 24

### 65. Notisinställningar
Kanalval, tysta timmar, ICS-länk att prenumerera på, webhooks för den som vill.
**Läs:** [[Notiser]], [[ADR-0010 Notisarkitektur]]
**Beror på:** 53, 31, 36, 37

### 66. Plan, förbrukning och gränser
Visa aktuell plan, förbrukning mot gränser, och vad som händer vid nedgradering **innan** den sker.
**Läs:** [[Planer och kvoter]], [[ADR-0009 Kvoter och livscykel]]
**Beror på:** 53, 25, 26, 27, 28

### 67. Utlåning, ägarbyte och export
De tre flödena i M6 som behöver en yta: markera utlånat med mottagare, initiera och acceptera ägarbyte, begära export.
**Läs:** [[Items och organisation]] § utlåning, [[Konton och åtkomst]] § ownership_transfer
**Beror på:** 57, 76, 39, 41, 74

### 68. Mobilanpassning och tillgänglighetsgenomgång
En genomgång, inte en ny funktion: vyerna används på telefon i en hamn med dålig uppkoppling. Tangentbordsnavigering, fokusordning, kontrast, träffytor, och att långsamma svar syns som något annat än en död sida.
**Klart när:** de fem vanligaste flödena — logga in, hitta ett item, ladda upp en fil, bocka av en uppgift, dela en container — går att genomföra på en telefon och med enbart tangentbord.
**Beror på:** 64, 61

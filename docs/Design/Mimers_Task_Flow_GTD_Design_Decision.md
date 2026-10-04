# MIMERS — Tasks, GTD och skapandeflödet

Designunderlag för arkitektur och implementation

## 1. Syfte

Detta dokument beskriver hur vi vill att Mimers ska hantera tasks, från skapande till genomförande, och varför vi väljer en enkel GTD-inspirerad modell framför Kanban, Waterfall eller andra projektmetoder.

Målet är ett system som är enkelt att förstå men kan skalas från en privatpersons båt eller fritidshus till företagsprojekt och side hustles.

## 2. Grundmodellen

Container → Items → Tasks

Mimers grundstruktur är generisk. En container representerar något användaren äger, använder eller arbetar med. Containern innehåller items. Ett item kan ha en eller flera tasks. Items kan ha flera parents och samma item kan därför visas på flera ställen i Structure-vyn.

Ett item kan även ha ett eller flera children. En parent har insyn i vad dess children gör, vilket innebär att tasks kan ärvas uppåt i vyerna. En task som hör till ett child item kan alltså visas även på parent-nivå och högre upp i strukturen, samtidigt som det ska vara tydligt vilket item tasken faktiskt tillhör.

En task är en konkret handling kopplad till ett item. Exempel: TT Freya → Motor → Serva motor. Samma modell fungerar för Kundprojekt → Landing page → Publicera sidan eller Side hustle → Webbutik → Uppdatera produkttexter.

## 3. Varför GTD?

Vi vill använda GTD-principerna som det igenkännbara arbetsflödet. Vi bygger inte in en fullständig projektmetodik. Mimers ska inte kräva sprintar, Kanban-kolumner, Waterfall-faser eller andra arbetsprocesser.

GTD är lätt att förstå utan metodutbildning, fungerar för både privatpersoner och företag, fungerar lika bra för underhåll som för projektarbete och kräver inte att användaren först definierar ett projekt eller en sprint. Det håller task-systemet generiskt och skalbart.

## 4. GTD-listorna

Inbox: Fångat men ännu inte bearbetat. Exempel: Ring leverantören

Next: Nästa konkreta handling. Exempel: Ring Volvo och boka service

Waiting: Väntar på någon eller något. Exempel: Väntar på offert

Calendar: Har ett bestämt datum eller tid. Exempel: Besiktning 14 oktober

Someday: Relevant men inte aktuellt nu. Exempel: Byta till större solpaneler

Done: Genomfört. Exempel: Service utförd

GTD-listan ska vara separerad från taskens status. 'Next' beskriver arbetskontexten; 'Ej påbörjad' eller 'Pågår' beskriver taskens faktiska tillstånd.

## 5. Skapa task från ett item

1. Användaren står på itemet Åror.

1. Klickar '+ Ny uppgift'.

1. Åror sätts automatiskt som kopplat item.

1. Användaren anger titel.

1. GTD-lista kan väljas, exempelvis Next.

1. Förfallodatum, påminnelse, upprepning, taggar och ansvarig är valfria.

1. Tasken skapas och visas direkt i itemets Tasks-vy.

Principen är: ju mer kontext systemet redan känner till, desto mindre behöver användaren ange manuellt.

## 6. Formuläret för ny task

Formuläret ska vara kraftfullt men inte kännas som ett ERP-formulär. En enkel task ska kunna skapas genom att bara ange en titel.

Titel — Obligatoriskt: Det konkreta resultatet/handlingen.

Beskrivning / anteckningar — Valfritt: Detaljer och instruktioner.

Kopplat item — Automatiskt: Sätts från aktuell vy; kan ändras.

GTD-lista — Valfritt/default: Inbox, Next, Waiting, Calendar, Someday, Done.

Status — Valfritt/default: Ej påbörjad, Pågår, Klar etc.

Förfallodatum — Valfritt: Ska inte krävas för vanliga tasks.

Påminnelse — Valfritt: Relativ eller absolut.

Upprepning — Valfritt: Viktigt för underhåll och rutiner.

Taggar/kategori — Valfritt: Generiskt; ingen domänlogik.

Kopplade items — Valfritt: Fler relevanta items kan länkas.

Ansvarig — Valfritt: Relevant vid samarbete.

## 7. Tasks på item-nivå

Itemets Tasks-flik visar som standard endast tasks som hör till det aktuella itemet. Det ska finnas en tydlig toggle för att även inkludera tasks från child items.

Eftersom en parent har insyn i sina children ska tasks kunna ärvas uppåt i vyerna. Det innebär exempelvis att en task på `Bränslefilter` kan visas på `Bränslesystem`, `Motor` och i slutändan containern, beroende på vilken nivå användaren befinner sig på och vilka filter som är aktiva.

När child tasks inkluderas ska varje task fortfarande visa vilket item den faktiskt kommer från. På så sätt blandas barnens uppgifter inte ihop med parent-itemets egna.

Arv ska vara en vyfunktion, inte skapa kopior av tasks. Samma task ska ha samma identitet och kunna nås från alla nivåer där den är synlig.

## 8. Tasks på container-nivå

Container-nivån har insyn i tasks från hela itemstrukturen. Tasks kan därför ärvas upp från children genom flera nivåer av parents och samlas i containerns task-vy.

- Lista som standard.

- Tavla och kalender kan vara alternativa presentationer av samma taskdata, inte olika arbetsmodeller.

- Filtrering på GTD-lista, status, item, tagg och ansvarig.

- Tydlig visning av vilket item varje task hör till.

- Snabb GTD-översikt med antal i Inbox, Next, Waiting, Calendar, Someday och Done.

## 9. Återkommande tasks

Återkommande tasks är centrala för Mimers. 'Serva motor varje år' ska kunna definieras en gång och sedan skapa nya förekomster när den föregående är klar.

Arkitekturen bör skilja mellan task-definitionen och enskilda occurrences. Då kan historiken bevaras samtidigt som användaren upplever det som en återkommande uppgift.

## 10. Multiple parents och relationer

Ett item kan ha flera parents. I Structure-vyn visas samma item under båda parents. Det är samma item och samma taskrelationer, inte två kopior.

Om Generator ligger under både Motor och Elsystem ska en task kopplad till Generator vara samma task oavsett från vilken gren användaren navigerar. Tasken är kopplad till itemets identitet, inte till en specifik visuell förekomst.

## 11. Vad vi medvetet inte bygger in

- Kanban som primär arbetsmodell.

- Waterfall-faser.

- Sprintar och backlog som obligatoriska begrepp.

- Tvingande projektstatusar.

- Komplexa workflows som måste konfigureras innan en task kan skapas.

- Domänspecifika tasktyper för båtar, fordon eller projekt.

Mimers kan senare integrera eller visualisera andra arbetssätt, men kärnan ska förbli enkel och generisk.

## 12. UX-principer

Context first: Skapa från item → item kopplas automatiskt.

Simple by default: Titel räcker för att skapa en task.

One task, one identity: Samma task visas i alla relevanta vyer utan duplicering.

Separate concerns: GTD-lista, status, datum, taggar och relationer är separata egenskaper.

Same data, many views: Dashboard, container, item, kalender och global To-do visar samma data.

No hidden domain knowledge: Mimers ska inte anta vad ett item betyder.

Visible context: Tasken ska kunna visa sitt item och sin container.

Scalable: Modellen ska fungera med få såväl som mycket stora datamängder.

## 13. Önskat slutflöde

Container → Item → Ny uppgift → Titel → GTD-lista → datum/upprepning vid behov → Skapa

Efter skapandet ska samma task kunna nås från itemet, containern och användarens globala To-do utan att användaren behöver förstå den underliggande datamodellen.

## 14. Frågor till arkitekten

1. Kan en task kopplas stabilt till ett item även om itemet har flera parents?

1. Kan en task kopplas till flera items utan att primärkopplingen blir oklar?

1. Hur modelleras återkommande tasks och enskilda occurrences?

1. Är GTD-listan separerad från task-status?

1. Kan samma task visas korrekt i global To-do, container, item och kalender?

1. Vad händer med tasks när ett item flyttas, får ytterligare parent eller tas bort?

1. Kan tasks från child items inkluderas utan att deras ursprung förloras?

1. Hur ska ansvarig och behörighet fungera när samarbete införs?

1. Vilka delar finns redan i applikationen och vilka saknas?

## 15. Slutsats

Vi vill att Mimers task-system ska kännas självklart snarare än kraftfullt på bekostnad av enkelhet. GTD ger oss ett etablerat språk för detta. Den generiska Mimers-modellen gör samtidigt att samma taskfunktion kan användas i helt olika sammanhang.

Arkitekturen bör därför stödja ett generiskt taskobjekt med tydliga relationer till items, medan UI:t presenterar detta genom enkla, kontextuella flöden. Den viktigaste designregeln är: användaren ska kunna börja med en titel och först därefter lägga till komplexitet när det behövs.

Mimers · Design discussion draft
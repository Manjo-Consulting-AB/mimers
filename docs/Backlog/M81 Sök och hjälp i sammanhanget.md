# M81 · Sök och hjälp i sammanhanget

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-23 ur #1568. Milstolpen bygger [[ADR-0094 Hjälpcentret]]: sökningen i hjälpen sker på servern, sökrutan visar träffarna medan man skriver, och appens vyer länkar till hjälpen för det de visar.

**Ordningen:** 466 väntar på 463. 467 väntar på 464, 465 och 466, 468 väntar på 464, och 469 väntar på 468. Issuerna är skapade i GitHub (#1580–#1583) och sammanfattade här. GitHub-issuen är bindande.

---

### 466. Sökningen i hjälpen på servern

GitHub #1580. En sökning i hjälpen på servern: `App\Support\Help\HelpSearch` och `GET /help/search?q=` som JSON. Den hittar artiklar på titel, nyckelord, beskrivning och text, förstår synonymer och början av ord, rangordnar titelträffar först och returnerar ett utdrag med träffarna markerade som segment. Ingen yta i den här issuen; 467 bygger sökrutan. Inget sökpaket och ingen Scout.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 463

### 467. Sökrutan i hjälpen och träfflistan

GitHub #1581. Sökrutan i hjälpen: på startsidan visar den förslag medan man skriver, och Enter öppnar en träfflista på `/help?q=…` som går att dela och backa till. Kategorisidorna och artikelsidorna får samma ruta i en mindre form. Den fungerar med tangentbord och skärmläsare, och visar ett läge för laddning, inga träffar och fel.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 464, 465, 466

### 468. Hjälp i sammanhanget: /help/context och HelpLink

GitHub #1582. Appens vyer kan länka till hjälpen för det de visar utan att känna till någon artikeladress: `/help/context/{sammanhang}` slår upp artikeln vars `context` bär sammanhanget och skickar vidare. Okänt sammanhang går till `/help`. Komponenten `HelpLink` är det enda sättet att bygga en sådan länk, och ett prov fäller en PR där en vy använder ett sammanhang som inte finns i innehållet.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 464

### 469. Hjälplänkarna i appens vyer

GitHub #1583. De vyer där användare oftast har frågor får en hjälplänk till rätt fråga: `HelpLink` från 468 med ett av de tretton sammanhangen som 461 lade i innehållet. Länken står till höger om vyns rubrik på samma rad, eller överst till höger i en fliks innehåll när vyn saknar egen rubrik. Ingen annan ändring i vyerna.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 468

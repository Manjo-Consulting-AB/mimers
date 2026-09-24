# Maila Mimers

**Status:** Under diskussion sedan 2026-09-24 · Inget byggt · Blir en ADR och en milstolpe när de öppna frågorna är avgjorda

Hur en användare ska kunna skicka in information i systemet via e-post. Filen samlar det som har avgjorts i diskussionen och det som fortfarande är öppet. När allt är avgjort flyttar motiveringen till en ADR och tabellerna till [[Items och organisation]], och den här filen blir historik.

## Bakgrund

Mimers går ut på att användaren registrerar information om sådant hon äger, använder eller arbetar med ([[ADR-0033 Produktens omfång]]). E-post är ett naturligt sätt att göra det: kvittot från webbutiken, fakturan från elbolaget eller servicerapporten från varvet finns redan i inkorgen.

Evernote hade funktionen men saknade styrning av var posten hamnade. Tony byggde därför en proxy via everandi.com som skrev om inkommande mail innan de nådde Evernote-kontot. Mimers ska ha den styrningen inbyggd.

## Avgjort

| Fråga | Beslut |
|---|---|
| Domän | `in.mimers.app`, skild från `mimers.app` och från utskicksdomänen `mg.mimers.app` |
| Plan | Pro-funktion |
| Kontots adress | Varje konto har en adress. Mail dit hamnar i en **standardcontainer** som användaren valt, och följer sedan den vanliga processen. Användaren flyttar själv itemet om det ska ligga någon annanstans |
| Adressformat | Korta genererade alias, inte ULID i adressen. En lokaldel får vara högst 64 tecken, och `konto+ULID+ULID` fyller 55 av dem |
| Mail till en container | Ett item skapas med ämnesraden som namn. Därefter hanteras mailet exakt som ett mail till ett befintligt item |
| Mail till ett item | Mailet läggs till i itemet. Bilagor blir filer på itemet |
| Lagring | En **egen tabell** med en rad per mail: tid, avsändare, ämne, brödtext och koppling till bilagorna. Varken `description` eller någon annan kolumn på itemet skrivs |
| Visning | Ren text i itemets anteckningar. Formatet liknar YAML men är inte YAML, så inga värden citeras. Datumet skrivs ISO-likt i kontots tidszon |
| Taggar och kategori | `#tagg` och `%kategori` i ämnesraden tolkas |
| HTML | Saneras |
| Avsändare | **Bara vitlista** från start. "Alla" och svartlista kan läggas till senare |
| Äkthet | SPF, DKIM och DMARC kontrolleras. Ett mail från en vitlistad adress som inte klarar DMARC behandlas som vid kvotstopp |
| Över kvoten | Mailet kastas tyst och kontoägaren får en notis. Avsändaren får **ingenting** tillbaka, varken svar eller studs |
| Vem betalar | Kontot som mailet tillskrivs. Mailar varvet in i en delad container betalar varvet, precis som vid uppladdning (`billed_account_id`, se [[Filer och lagring]]) |
| Skapare | Om avsändaren är en användare i kontot blir den användaren `created_by_user_id`. Annars blir kontoägaren det. Mailraden sparar alltid avsändarens adress som text. `created_by_account_id` är kontot som betalar |
| Nedgradering från Pro | Adressen finns kvar och fungerar igen vid uppgradering. Mail som kommer under tiden behandlas som vid kvotstopp |
| Personuppgifter | Mailets innehåll är användarens data, och Mimers behandlar det som biträde, precis som allt annat användaren lägger in. Vi loggar att ett mail kom och från vilken adress, men inte innehållet. Avsändaradressen hör hemma i **säkerhetsloggen** med dess gallring, eftersom [[ADR-0043 Tre loggar]] förbjuder e-postadresser i händelseloggens `meta`. Mailgun blir biträde också för inkommande innehåll, och det ska stå i [[Registerförteckning]] |
| Routningsregler | **På is.** Regler i stil med en brandvägg, uppifrån och ner med första träff, byggs inte nu |

Så här ser ett mail ut i anteckningarna:

```
Time: 2026-09-24 14:32
Sender: kvitto@exempel.se
Subject: Din beställning 48213
Body:
…
```

## Flödet som det ser ut nu

```
[mail till in.mimers.app]
  → [Mailgun tar emot och skickar vidare till appen, svar 200 oavsett vad som händer sedan]
  → [DMARC klarat?]              nej → kasta tyst, notis till ägaren
  → [avsändaren på vitlistan?]   nej → kasta tyst, notis till ägaren
  → [kontot har Pro och ryms i kvoten?]  nej → kasta tyst, notis till ägaren
  → [vilket mål pekar aliaset ut?]
       kontots adress  → standardcontainern → skapa item
       container       → skapa item
       item            → det itemet
  → [lägg mailet som en rad i mailtabellen, bilagor som attachments]
  → [tolka #tagg och %kategori]
```

**Svar 200 till Mailgun även när mailet kastas.** Svarar appen med ett fel försöker Mailgun igen, och i värsta fall studsar mailet tillbaka till avsändaren. Det skulle bryta beslutet om att avsändaren aldrig får något. `Message-ID` används för att samma mail inte ska läggas in två gånger när Mailgun skickar om.

## Öppet

### Containerns adress och vem ett mail tillskrivs

Tony: när varvet är inbjudet till en container ska de se containerns adress och kunna använda den. Det betyder att adressen bara pekar ut ett **mål**. Vilket **konto** mailet tillskrivs och belastar måste avgöras av **avsändaren**:

> Adressen säger *vart*. Avsändaren säger *vem som betalar*.

Följderna, som inte är avgjorda:

- Vitlistan hör till kontot. Ett mail till containerns adress slås upp mot vitlistorna hos de konton som har åtkomst till containern, med minst `create`-nivå och en åtkomst som varken är återkallad eller utgången.
- Samma adress kan stå på två kontons vitlistor, till exempel både ägarens och varvets. Förslag: ägarkontot vinner.
- Pro prövas på avsändarens konto. Varvet med Pro kan då maila in i en container vars ägare har Free. Har containern en adress även när ägaren saknar Pro?
- Vem får notisen när mailet kastas eftersom ingen vitlista matchar? Ägaren är den enda rimliga mottagaren, men det betyder att ägaren får notiser om mail som var menade för varvet.
- Hur förhåller sig kontots adress (till standardcontainern) till containerns alias? Är det samma mekanism med olika mål?

### Mail till ett item: lägga till eller skapa ett child item

Beslutet så här långt är att mail till ett item läggs till i itemet. Frågan är om det också ska gå att skapa ett child item.

**Fördelar med child item**
- Varje mail blir ett eget objekt med egna taggar, egen kategori och egna filer, och kan sökas fram, flyttas och delas för sig.
- Det passar kvittot som hör till en produkt.
- Det är billigt att bygga: samma väg som mail till en container, plus en `parent`-länk i `item_link`.

**Nackdelar**
- Varje item behöver två alias, och användaren måste förstå skillnaden mellan dem.
- `parent`/`child` bär behörighet nedåt ([[ADR-0028 Åtkomst på itemnivå]]). Ett inkommande mail utvidgar alltså en befintlig delning utan att någon tittar på det.
- Ett item kan få många barn som bara är mail, och det skräpar ner relationsgrafen.

**Rekommendation:** börja med att bara lägga till i itemet, men utforma aliasschemat så att en variant för barn kan läggas till senare utan att befintliga adresser går sönder.

**Hänger ihop med detta:** vad `#tagg` och `%kategori` gör när mailet läggs till i ett **befintligt** item. Att taggar läggs till är ofarligt. Att ett mail byter itemets kategori är mer tveksamt. Blir det ett child item försvinner frågan, eftersom taggarna och kategorin då hamnar på barnet.

### Att ta bort ett mail

Vad händer när användaren tar bort ett mail ur ett item? Raden i mailtabellen försvinner. Frågan är om bilagorna ska följa med eller bli kvar som vanliga filer på itemet. Hör raden till papperskorgen ([[ADR-0008 Soft delete och papperskorg]]) på samma villkor som ett item och en attachment?

### Mindre frågor som inte har diskuterats

- **Tolkningen av `%kategori`.** Kategorier är hierarkiska, så samma namn kan finnas på flera nivåer. Namn med mellanslag går inte att skriva med den föreslagna syntaxen. Vad händer om ämnet innehåller två `%`? Får ett mail skapa en tagg eller kategori som inte finns?
- **Tomt ämne.** Vad får itemet för namn när ett mail till en container saknar ämnesrad? `name` rymmer dessutom högst 255 tecken, så långa ämnen måste kortas.
- **Tak för notiserna.** Hundra kastade mail ska inte bli hundra notiser. Förslag: en samlad notis per tidsfönster.
- **Flytt mellan containers.** Kontots adress bygger på att användaren kan flytta ett item från standardcontainern. Kontrollera att flytten finns. Taggar och kategorier är per container, så det måste bestämmas vad som händer med dem vid en flytt.
- **Att byta ut ett alias.** Ett alias fungerar i praktiken som en nyckel. Det bör gå att byta ut, som ICS-flödet.
- **Storleksgränser.** Pro-gränsen är 64 MB per fil. Mailgun har en egen storleksgräns för inkommande meddelanden, och de två behöver stämmas av.

## Att verifiera

- **Mailgun för inkommande post.** Kostnaden för inkommande routes med er plan, storleksgränsen och vilka SPF-, DKIM- och DMARC-resultat som skickas med till appen. Tony kontrollerar.
- **Alternativet TempMail/Mailshield.** Tony har ett liknande system där som redan läser in mail. Jämför det med Mailgun på MIME-tolkning, DMARC-kontroll och hur mailen levereras till appen.

## När det här blir ett beslut

- En ADR, `risk_class: elevated`. Funktionen är en ny väg in i systemet som rör behörighet, kvoter och filer, och den bör läggas till i [[ADR-0017 Missbruksvektorer]].
- Mailtabellen och aliasen läggs in i [[Items och organisation]] eller [[Konton och åtkomst]], beroende på var aliasen hamnar.
- `in.mimers.app` och den nya webhooken läggs in i [[Pipeline]].
- Mailgun som biträde för inkommande innehåll läggs in i [[Registerförteckning]].
- Raden `Maila Mimers` i [[Tankar]] stryks.

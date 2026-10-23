# ADR-0068 Itemets sida och skapandet

**Status:** Antagen 2026-10-23 · Ändrar [[ADR-0041 Itemets vy]] (sidopanelerna får fällas ihop), [[ADR-0063 Itemets karta i containerkartans form]] § 1 (kartan under `lg:`) och [[ADR-0066 Itemets anteckning]] (anteckningen lämnar skapaformuläret) · Kompletterar [[ADR-0032 Produktens ord]] med itemets ord · Följer [[ADR-0050 Desktopdesignen]] § Uppföljning 2026-10-17 § 4 (*To do*) · [[ADR-index]]

Underlaget är en UX-granskning av itemvyerna 2026-10-23 och två arkitekters genomgång av den. Förlagan är `docs/Design/struktur - item.jpeg`, samma bild som [[ADR-0041 Itemets vy]] byggde på.

## Kontext

Itemets sida har vuxit genom att funktioner lagts till en i taget. Varje tillägg hade ett eget beslut, men ingen har beslutat om helheten.

- **Nio flikar:** Overview, Information, Relations, Attachments, Tasks, Loans, Tags, Costs och History. På desktop bryts de till två rader. Översikten visar *Quick facts* och en länk till Information, som visar samma fält en gång till. Taggarna har en egen flik, och den var tom när itemet saknade taggar.
- **Åtgärderna har samma vikt.** *Edit*, *Delete* och *New item under this one* är tre textlänkar på samma rad. *Delete* ligger bredvid *Edit* och går direkt till webbläsarens `window.confirm`.
- **Skapaformuläret är platt.** Det har ungefär 13 fält med samma vikt, och bara namnet är obligatoriskt. Ikon och omslag går bara att välja vid redigering. Saknas taggar eller kategorier leder formuläret bort till en annan sida, och det som skrivits in går förlorat.
- **Orden glider isär.** *Placement* (förälder i strukturen), *Where it is* och *Location* (`position_note`) och *Placements in the structure* är fyra ord för två begrepp. Itemets flik heter *Attachments*, containerns *Documents* och plusmenyns rad *Image or document*. Itemets flik säger *Tasks*, fast containerns flik och huvudmenyn säger *To do* sedan ADR-0050.

Förlagan har tre paneler, sju flikar (Översikt, Detaljer, Relationer, Dokument, Kostnader, Uppgifter, Historik), en primärknapp *Redigera* med en ⋯-meny, fälten i ett rutnät bredvid bilden, en rad med snabbåtgärder och relationerna på översikten.

## Beslut

### Orden

**1. Itemets ord.** Tabellen kompletterar [[ADR-0032 Produktens ord]] och gäller i varje sträng användaren möter på itemets sida, i formulären, i plusmenyn och i containerns flikar.

| Begrepp | Svenska | Engelska | Koden |
|---|---|---|---|
| Var itemet hör hemma i hierarkin, dess föräldrakanter | **Plats i strukturen** | **Placement** | `item_link` (parent) |
| Fritext om var saken faktiskt finns: "styrbords stuvfack under kojen" | **Förvaringsplats** | **Storage location** | `position_note` |
| Filer på itemet, bilder inräknade | **Dokument** | **Documents** | `attachment` |
| Vad itemet *är*, en eller två rader | **Beskrivning** | **Description** | `description` |
| Vad användaren *vet* om itemet, Markdown | **Anteckningar** | **Notes** | `notes` |
| Datum då itemet köptes | **Inköpsdatum** | **Purchase date** | `purchased_at` |
| Uppgifter och underhåll | **Att göra** | **To do** | `schedule` |

*Where it is*, *Location*, *Attachments*, *Image or document* och *Purchased* utgår. Nycklarna i `lang/en/ui.php` står still och byter bara värde, som ADR-0032 föreskriver.

### Sidan

**2. Tre paneler som utgångspunkt, med sidopanelerna infällbara.** Från `lg:` står strukturen, itemet och kartan bredvid varandra som i förlagan och [[ADR-0041 Itemets vy]]. Struktur- och kartpanelen kan var för sig fällas ihop till en smal list. Valet sparas per webbläsare (`localStorage`), och en hopfälld panel fälls ut med ett klick. Under `lg:` visas strukturen som en utfällbar rad ovanför itemet, och kartan flyttar in på översikten (§ 5).

**3. Huvudet.** Huvudet visar ikonen eller omslagets miniatyr, namnet, kategorin som chip och **taggarna som chips** direkt under namnet. Till höger står favoritstjärnan, **primärknappen *Edit*** och en **⋯-meny** med *New item under this one* och sist *Move to trash*. Ingen åtgärd står som fristående textlänk. Brödsmulan börjar med containerns namn, som leder till itemfliken.

**4. Flikarna följer förlagan med två motiverade avvikelser.** I den här ordningen: **Overview · Relations · Documents · Costs · To do · Loans · History.**
- *Detaljer/Information* utgår. Itemet har åtta fält, och översikten visar alla som är ifyllda (§ 5). En flik med samma fält en gång till är en dubblett.
- *Loans* läggs till, före History. Utlåningen fick egen flik i issue 102 just för att en yta ingen hittar är samma sak som en yta som inte finns. Det skälet gäller fortfarande.
- *Tags* utgår som flik och ritas i huvudet (§ 3).
- **En flik med noll rader bär ingen räknare.** Det ändrar issue 99:s regel att `0` ritas.

**5. Översikten är sammanfattningar med tak.** Översikten ritar, uppifrån och ned:
1. **Bild och fält.** Omslaget till vänster och **varje ifyllt fält** i ett rutnät med två kolumner: kategori, tillverkare, modell, serienummer, inköpsdatum, garanti och förvaringsplats. Ingen rubrik *Quick facts* och ingen länk *Show all fields*.
2. **Beskrivningen**, under fälten.
3. **Snabbåtgärder**, en rad med de åtgärder användaren får göra: *Add document*, *Add task*, *Add cost* och *Add relation*. Var och en öppnar samma formulär som motsvarande flik.
4. **En pågående utlåning**, som en rad med låntagare och återlämningsdatum, bara när itemet är utlånat.
5. **Kartan i sin smala form, bara under `lg:`.** Från `lg:` står den i högerpanelen, och ingen relationslista ritas på översikten. Relationerna visas därför en gång på varje bredd, aldrig två gånger.
6. **Anteckningarna**, oförändrade enligt [[ADR-0066 Itemets anteckning]].
7. **Senaste aktivitet**, högst fem händelser som i dag, med en länk till History.

Ingen sektion på översikten listar fler än fem rader. Den som vill se mer följer länken till fliken.

**6. Radering bekräftas i appens egen dialog.** Dialogen namnger itemet, säger att det flyttas till papperskorgen i 30 dagar och har *Cancel* som förval för tangentbordet. `window.confirm` används inte på itemets sida. Komponenten byggs så att containerns och bilagornas radering kan gå över till den senare, men de flyttas inte i den här ADR:n.

**7. Varje tom yta säger en mening och erbjuder en åtgärd.** Åtgärden visas bara när användaren får utföra den. Det gäller flikarna, översiktens tomma läge och huvudets tomma taggrad.

### Skapandet

**8. Skapaformuläret har två nivåer.** Direkt synliga är **namn, plats i strukturen, kategori, taggar och ikon**. Under en utfällbar **More details** står beskrivning, tillverkare, modell, serienummer, inköpsdatum, garanti, förvaringsplats och, när användaren är medlem i fler än ett konto, kontot. **Anteckningar finns inte i skapaformuläret.** De skrivs på itemets sida enligt ADR-0066, och två textfält bredvid varandra var det som gjorde skillnaden mot beskrivningen otydlig.

**9. Formuläret har *Cancel* och *Save and add another*.** *Cancel* leder tillbaka dit användaren kom ifrån: förälderns sida, eller itemfliken när det inte finns någon förälder. *Save and add another* skapar itemet och öppnar ett nytt formulär med **samma plats i strukturen och samma kategori**. Övriga fält töms, och en rad överst säger vilket item som just skapades, med en länk till det.

**10. Formuläret varnar innan inmatning går förlorad.** Lämnar användaren ett ändrat formulär utan att spara frågar appen först, med samma dialog som § 6. Det gäller skapandet och redigeringen.

**11. En bild vid skapandet laddas upp efter att itemet skapats, i samma gest.** Formuläret har ett fält *Image*. Vid *Save* skapas itemet först, och sedan laddas bilden upp mot itemets **befintliga** uppladdningsändpunkt. Bilden blir omslag genom regeln i ADR-0041: utan ett val är omslaget itemets äldsta bild. Ingen fil finns alltså i systemet utan ett item, och deduplicering, referensräkning och kvot går genom samma väg som varje annan uppladdning. Misslyckas uppladdningen, till exempel för att kvoten är slut, finns itemet ändå. Användaren landar på itemets sida med felet och fliken Documents öppen. Ingen ny ändpunkt byggs.

**12. Omslaget väljs bland miniatyrer.** I redigeringen ersätts väljaren med filnamn av ett rutnät med itemets bilder och rutan *No cover*. I fliken Documents får varje bild raden *Use as cover*.

**13. Taggar och kategorier väljs i samma komponent.** En sökbar väljare med valda värden som chips används i formulären och i itemflikens filterrad. Kategorin tar ett värde och taggarna flera. Datamodellen för de två ändras inte.

**14. Nya taggar och kategorier skapas direkt i väljaren, under samma grind som i dag.** Raden *Create "X"* visas bara när användaren klarar `ContainerPolicy::update`, som är samma grind som `TagController::store` och `CategoryController::store` prövar. Skapandet anropar de befintliga ändpunkterna med Inertias `preserveState`. Formulärets inmatning står kvar, och det nya värdet väljs. Ingen ny ändpunkt och ingen ny behörighetsregel. En mottagare med begränsat omfång ser ingen sådan rad. Länkarna *Create tags* och *Create categories* bort från formuläret utgår.

**15. Redigeringen sker i formuläret, med två undantag.** Itemets fält redigeras i *Edit*, med *Save* och *Cancel*. Undantagen är anteckningarna, som sparas av sig själva enligt ADR-0066, och favoritstjärnan. Ingen ytterligare inline-redigering införs här.

### Listan

**16. Trädtabellens rader.** Hela raden är klickbar. Dokument- och uppgiftskolumnerna är tomma i stället för `0`. Fällknappens kolumn behåller sin bredd, så att namnen står i linje, men den ritas utan synlig ruta på löv-rader. Filterradens taggar använder väljaren ur § 13, och sökfältet har en platshållare i stället för en egen etikett.

## Motivering

**Förlagan är den beslutade riktningen.** ADR-0041 byggde på den. Att börja om hade kastat bort ett beslut utan att ha ett nytt skäl. Avvikelserna i § 4 har var sitt skäl, och ingen av dem följer av ett mål om ett visst antal flikar.

**Ord före flikar.** Fliknamnen, formulärets etiketter och plusmenyns rader är samma ord. Hade de bestämts var för sig hade de glidit isär igen.

**Bilden efter itemet håller lagringsreglerna orörda.** En fil utan item hade krävt en ny ägare för filen medan formuläret är öppet, en kvot för den och en städning när formuläret överges. Regeln om äldsta bild i ADR-0041 gör att en bild vid skapandet blir omslag utan något val.

**Skapandet i väljaren ändrar ingen behörighet.** Grinden är densamma som taggsidans, och ändpunkterna är desamma. Det enda som ändras är var knappen står.

## Konsekvenser

- Byggs som en ny milstolpe, *Itemets sida*. Ordningen är ordlistan (§ 1), huvudet och raderingsdialogen (§ 3, 6), flikarna och översikten (§ 4, 5, 7), panelerna (§ 2), väljaren (§ 13, 14), skapaformuläret (§ 8–11), omslaget (§ 12) och listan (§ 16).
- **Ingen issue i milstolpen får etiketten `Build`.** Etiketten lägger en issue i den automatiska kön (`.github/scripts/process_next_issue.py`), och den här milstolpen ska inte byggas därifrån. Issuerna implementeras i stället av Sonnet-subagenter som en Opus-session styr. Det avviker från [[ADR-0026 Implementering och granskning efter riskaxlar]], där Deepseek implementerar och Sonnet granskar, och gäller bara den här milstolpen. Tonys beslut 2026-10-23.
- Alla issues utom § 11 och § 14 rör bara frontend och strängar: `risk_class: none`.
- **§ 11 och § 14 är `risk_class: elevated`.** De rör uppladdning respektive skapande under en behörighetsgrind, även om de inte ändrar någon regel. Granskningen ska bekräfta att ingen ny ändpunkt och ingen ny grind tillkommit. Kräver implementationen något av det, ska den stanna och fråga.
- `ItemflikTest` byter flikförteckning och räknarregel. Proven för Information och Tags går ut, liksom provet för taggflikens tomma rad (2026-10-23).
- `ItemQuickFacts` och `itemQuickFacts()` ersätts av fältrutnätet. `ItemPlacementsPanel` står kvar i högerpanelen.
- `item.show.all_fields`, `item.show.quick_facts`, `item.form.notes`, `item.form.categories_empty_link` och `item.form.tags_empty_link` utgår ur `lang/en/ui.php`.

## Tonys beslut 2026-10-23

Utkastet hade tre öppna frågor. Tony följde rekommendationen i alla tre:

1. **Sidopanelerna fälls ihop** enligt § 2, och valet sparas per webbläsare.
2. **En flik med noll rader bär ingen räknare** enligt § 4. Det ändrar issue 99.
3. **Bilden vid skapandet laddas upp efter itemet, i samma gest,** enligt § 11.

## Alternativ

**Fem flikar som mål.** Översikt, Innehåll, Uppgifter, Relationer och Mer. Valdes bort: antalet var ett mål i sig och inte ett skäl. *Innehåll* hade varit ett femte ord för dokumenten, och *Mer* gömmer lån och historik bakom ett klick utan att säga vad som finns där.

**Ta bort sidopanelerna.** Valdes bort: det river upp ADR-0041:s trepanel och förlagan. Att fälla ihop dem ger utrymmet utan att ta bort överblicken.

**Ladda upp bilden före skapandet till en tillfällig plats, till exempel inboxen.** Valdes bort: filen behöver då en ägare, en kvot och en städning innan itemet finns. Inboxen ([[ADR-0054 Inboxen]]) är en plats för det som saknar hemvist och ingen mellanlagring för ett formulär.

**Inline-redigering av alla fält på översikten.** Valdes bort: en samlad redigering med *Save* och *Cancel* är tydligare för en grupp fält som hör ihop, och den gör det uppenbart hur ändringar sparas.

**En ny JSON-ändpunkt för att skapa taggar.** Valdes bort: Inertias `preserveState` mot de befintliga ändpunkterna ger samma resultat utan en ny yta att skydda.

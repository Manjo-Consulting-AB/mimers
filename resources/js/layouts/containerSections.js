/*
 * Containerns sektioner, se issue 54 § Beslut 7.
 *
 * Samma konstruktion som settingsSections.js och av samma skäl: 55a
 * (delning), 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63
 * (scheman) får alla en sida per container, och uppfinner var och en sin egen
 * undernavigering blir det fem. En ny sida är en ny rad HÄR och ingen ändring
 * i ContainerLayout.
 *
 * `href` är en FUNKTION av containerns ULID, till skillnad från
 * settingsSections konstanta strängar — det är hela skillnaden mot förlagan.
 * Sektionerna ligger under en container, och vilken container det är vet bara anroparen.
 *
 * `key` är både Vue-nyckeln och sista ledet i översättningsnyckeln
 * (`container.nav.<key>` i lang/{locale}/ui.php). Ingen färdig mening här:
 * texten formuleras på servern och slås bara upp på klienten, se
 * [[ADR-0021 Frontendteknik]] och resources/js/composables/useTranslations.js.
 *
 * **Tio rader, och varken fler eller färre.** Listan är containerns sidor, och
 * issue 101 · [[ADR-0042 Designsystemet]] § Konsekvenser delar dem i två ytor:
 * flikraden (`containerTabs`) och inställningssidan
 * (`containerSettingsSections`). Ingen rad får försvinna — en yta ingen hittar
 * är samma sak som en yta som inte finns, och det är 62a:s motivering för
 * papperskorgen och 67c:s för exporten. En ny SIDA är fortfarande en ny rad
 * här; vilken av de två ytorna den hamnar på avgörs av `TAB_KEYS` nedan.
 *
 * `items` kom med issue 57a § Beslut 1 och ligger FÖRST: itemen är containern,
 * och kategorierna och taggarna är hur den är ordnad. Sedan issue 89 ·
 * [[ADR-0039 Containerns översikt]] pekar raden på `/containers/{ulid}/items` —
 * itemlistan flyttade dit när containerns egen URL blev en översikt — och den är
 * därmed en undersida som de andra. Översikten har fortfarande ingen egen rad:
 * den är inte en undersida, och dess adress står i `containerTabs` i stället.
 *
 * `sharing` kom med issue 55a § Beslut 1. Raden var allt som krävdes:
 * navigationen renderades ur den här listan, så en ny sektion var en ny rad här
 * och ingen ändring i ContainerLayout. 55b lägger sin inbjudningsyta som en
 * tredje SEKTION på samma sida, inte som en egen rad — den hör till
 * delningen.
 *
 * `categories` och `tags` kom med issue 56a § Beslut 1: två sidor, två rader.
 * De ligger före `sharing` därför att strukturen är det man arbetar i och
 * delningen det man ställer in — ordningen är hur en användare möter containern,
 * inte hur issues råkade bli klara.
 *
 * `trash` kom med issue 62a § Beslut 1 och ligger SIST, efter `settings`:
 * papperskorgen är dit man går när något gått fel, inte en yta man arbetar
 * i. Den är ändå en rad och ingen sidfot — en väg tillbaka som ingen hittar
 * är samma sak som ingen väg tillbaka.
 *
 * `calendar` kom med issue 65b § Beslut 1 och ligger efter `settings`, före
 * `trash`: kalenderlänken är en UTGÅNG ur produkten och inte en yta man
 * arbetar i — containerns uppgifter prenumererade på ur någon annans kalender —
 * men den hör till containerns inställningar och inte till papperskorgen, som är
 * dit man går när något gått fel.
 *
 * `transfer` kom med issue 67b § Beslut 1 och ligger SIST, efter `trash`: ett
 * ägarbyte är den mest konsekvensrika handlingen i produkten — hela containern
 * byter konto — och det är inte något man gör ofta. Raden är ändå en rad: en
 * yta ingen hittar är samma sak som en yta som inte finns, och den som ska
 * överlåta en båt står i containern när hon bestämmer sig.
 *
 * `export` kom med issue 67c § Beslut 1 och ligger efter `calendar`, före
 * `trash`: exporten är en UTGÅNG ur produkten, precis som kalenderlänken —
 * där länken för containerns uppgifter ut i någon annans kalender, tar exporten
 * hela containern ut i en fil — men den hör inte till papperskorgen, som är dit
 * man går när något gått fel. Den är fri på alla plannivåer med flit
 * ([[Planer och kvoter]] § Gränserna i MVP): *"påminnelserna skapar vanan,
 * exporten skapar förtroendet"*. Raden låg därför i NAVIGERINGEN och ligger
 * sedan issue 101 på inställningssidan, som är containerns skyltade
 * samlingsplats — en utgång ingen hittar är samma sak som en inlåsning.
 *
 * `history` kom med issue 116 · [[ADR-0043 Tre loggar]] § Händelseloggen och
 * ligger SIST, efter `transfer`: historiken är vad som HAR hänt, och den är
 * ingen yta man arbetar i utan den man läser efteråt. Den är ändå en flik och
 * ingen sidfot — issue 101 lämnade platsen tom med flit, med orden
 * *"historiken ritas inte ännu"*, och den förutsättningen faller här. Raden
 * hör till flikraden (`TAB_KEYS` nedan): bilden ritar historiken jämte
 * översikten och items, och den som undrar vad som hänt letar där hon mötte
 * resten — inte på inställningssidan.
 */
export const containerSections = [
    { key: 'items', href: (ulid) => `/containers/${ulid}/items` },
    { key: 'categories', href: (ulid) => `/containers/${ulid}/categories` },
    { key: 'tags', href: (ulid) => `/containers/${ulid}/tags` },
    { key: 'sharing', href: (ulid) => `/containers/${ulid}/sharing` },
    { key: 'settings', href: (ulid) => `/containers/${ulid}/edit` },
    { key: 'calendar', href: (ulid) => `/containers/${ulid}/calendar` },
    { key: 'export', href: (ulid) => `/containers/${ulid}/export` },
    { key: 'trash', href: (ulid) => `/containers/${ulid}/trash` },
    { key: 'transfer', href: (ulid) => `/containers/${ulid}/transfer` },
    { key: 'history', href: (ulid) => `/containers/${ulid}/history` },
];

/*
 * Sektionerna som stannar i flikraden. Resten samlas på inställningssidan.
 *
 * `items` är den yta man arbetar i — itemen är containern (57a § Beslut 1) —
 * och `settings` är inställningssidan, som bär de sju andra och därför måste
 * gå att nå från varje sida i containern. Den ritades som en knapp i bildens
 * hjälte (*Redigera container*), och hjälten byggs inte ännu; fliken är samma
 * adress och samma yta, och den syns från varje flik i stället för från en.
 */
const TAB_KEYS = ['items', 'settings', 'history'];

/*
 * Flikraden, se issue 101 · [[ADR-0042 Designsystemet]] § Beslut och
 * § Bildernas avvikelser.
 *
 * **Bildens sju flikar blir fyra här, och de tre som fattas är inte glömda.**
 * Bilden ritar översikt, items, dokument, uppgifter, underhåll, kostnader och
 * historik. Uppgifter och underhåll är EN flik (§ Bildernas avvikelser:
 * `schedule` skiljer dem bara åt via `recurrence_type`, och skillnaden är ett
 * filter i listan), och historiken ritades inte när issue 101 skrevs
 * (§ Konsekvenser: `audit_log` instrumenteras i ett eget arbete). Issue 116
 * bygger den, och raden är den tionde i listan ovan.
 *
 * **Dokument, uppgifter och kostnader har ingen sida.** Ingen rutt svarar på
 * dem, ingen kontrollermetod hämtar dem och ingen prop bär dem, så en flik för
 * dem hade varit en död länk — och en yta ingen hittar är samma sak som en yta
 * som inte finns (62a, 67c). De byggs därför inte här: issue 101 får ingen ny
 * ändpunkt, och en flik som kräver en ny kontrollermetod är ett fynd i PR:ens
 * `## Frågor och antaganden` och inte en ändpunkt i smyg.
 *
 * **Översikten skrivs här och inte i listan ovan**, för den är containerns egen
 * sida och ingen undersida (`containers.show`, issue 89). `items`, `settings`
 * och `history` är sektioner och har sin rad i listan; fliken är samma nyckel
 * och samma adress, och därför ingen andra formulering av samma sak.
 *
 * `count` sätts inte: flikarna bär inga tal i bilden, och `UiTabs` ritar en
 * bricka bara när anroparen har ett tal att visa (issue 100).
 */
export const containerTabs = [
    { key: 'overview', href: (ulid) => `/containers/${ulid}` },
    ...containerSections.filter((section) => TAB_KEYS.includes(section.key)),
];

/*
 * Inställningssidan, se issue 101.
 *
 * De sju sektionerna som inte fick plats i flikraden: kategorier, taggar,
 * delning, kalender, export, papperskorg och överlåtelse. Ordningen är
 * containerSections egen — strukturen först, utgångarna efter, papperskorgen
 * och ägarbytet sist — så en rad flyttar aldrig i förhållande till sina grannar
 * när ytorna delas.
 */
export const containerSettingsSections = containerSections.filter(
    (section) => ! TAB_KEYS.includes(section.key),
);

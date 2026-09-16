/*
 * Pärmens sektioner, se issue 54 § Beslut 7.
 *
 * Samma konstruktion som settingsSections.js och av samma skäl: 55a
 * (delning), 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63
 * (scheman) får alla en sida per pärm, och uppfinner var och en sin egen
 * undernavigering blir det fem. En ny sida är en ny rad HÄR och ingen ändring
 * i ContainerLayout.
 *
 * `href` är en FUNKTION av pärmens ULID, till skillnad från
 * settingsSections konstanta strängar — det är hela skillnaden mot förlagan.
 * Sektionerna ligger under en pärm, och vilken pärm det är vet bara anroparen.
 *
 * `key` är både Vue-nyckeln och sista ledet i översättningsnyckeln
 * (`container.nav.<key>` i lang/{locale}/ui.php). Ingen färdig mening här:
 * texten formuleras på servern och slås bara upp på klienten, se
 * [[ADR-0021 Frontendteknik]] och resources/js/composables/useTranslations.js.
 *
 * `items` kom med issue 57a § Beslut 1 och ligger FÖRST: itemen är pärmen,
 * och kategorierna och taggarna är hur den är ordnad. `href` pekar på pärmens
 * EGEN URL — `/containers/{ulid}` är förstasidan och inte en undersida, och
 * den raden är därför den enda vars href är ett prefix till de andra.
 *
 * `sharing` kom med issue 55a § Beslut 1. Raden är allt som krävdes: layouten
 * renderar navigationen ur den här listan, så en ny sektion är en ny rad här
 * och ingen ändring i ContainerLayout. 55b lägger sin inbjudningsyta som en
 * tredje SEKTION på samma sida, inte som en egen rad — den hör till
 * delningen.
 *
 * `categories` och `tags` kom med issue 56a § Beslut 1: två sidor, två rader.
 * De ligger före `sharing` därför att strukturen är det man arbetar i och
 * delningen det man ställer in — ordningen är hur en användare möter pärmen,
 * inte hur issues råkade bli klara.
 *
 * `trash` kom med issue 62a § Beslut 1 och ligger SIST, efter `settings`:
 * papperskorgen är dit man går när något gått fel, inte en yta man arbetar
 * i. Den är ändå en rad och ingen sidfot — en väg tillbaka som ingen hittar
 * är samma sak som ingen väg tillbaka.
 *
 * `calendar` kom med issue 65b § Beslut 1 och ligger efter `settings`, före
 * `trash`: kalenderlänken är en UTGÅNG ur produkten och inte en yta man
 * arbetar i — pärmens uppgifter prenumererade på ur någon annans kalender —
 * men den hör till pärmens inställningar och inte till papperskorgen, som är
 * dit man går när något gått fel.
 *
 * `transfer` kom med issue 67b § Beslut 1 och ligger SIST, efter `trash`: ett
 * ägarbyte är den mest konsekvensrika handlingen i produkten — hela pärmen
 * byter konto — och det är inte något man gör ofta. Raden är ändå en rad: en
 * yta ingen hittar är samma sak som en yta som inte finns, och den som ska
 * överlåta en båt står i pärmen när hon bestämmer sig.
 *
 * `export` kom med issue 67c § Beslut 1 och ligger efter `calendar`, före
 * `trash`: exporten är en UTGÅNG ur produkten, precis som kalenderlänken —
 * där länken för pärmens uppgifter ut i någon annans kalender, tar exporten
 * hela pärmen ut i en fil — men den hör inte till papperskorgen, som är dit
 * man går när något gått fel. Den är fri på alla plannivåer med flit
 * ([[Planer och kvoter]] § Gränserna i MVP): *"påminnelserna skapar vanan,
 * exporten skapar förtroendet"*. Raden ligger därför i NAVIGERINGEN och inte
 * bakom en inställning — en utgång ingen hittar är samma sak som en inlåsning.
 */
export const containerSections = [
    { key: 'items', href: (ulid) => `/containers/${ulid}` },
    { key: 'categories', href: (ulid) => `/containers/${ulid}/categories` },
    { key: 'tags', href: (ulid) => `/containers/${ulid}/tags` },
    { key: 'sharing', href: (ulid) => `/containers/${ulid}/sharing` },
    { key: 'settings', href: (ulid) => `/containers/${ulid}/edit` },
    { key: 'calendar', href: (ulid) => `/containers/${ulid}/calendar` },
    { key: 'export', href: (ulid) => `/containers/${ulid}/export` },
    { key: 'trash', href: (ulid) => `/containers/${ulid}/trash` },
    { key: 'transfer', href: (ulid) => `/containers/${ulid}/transfer` },
];

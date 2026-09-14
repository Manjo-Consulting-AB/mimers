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
 */
export const containerSections = [
    { key: 'items', href: (ulid) => `/containers/${ulid}` },
    { key: 'categories', href: (ulid) => `/containers/${ulid}/categories` },
    { key: 'tags', href: (ulid) => `/containers/${ulid}/tags` },
    { key: 'sharing', href: (ulid) => `/containers/${ulid}/sharing` },
    { key: 'settings', href: (ulid) => `/containers/${ulid}/edit` },
];

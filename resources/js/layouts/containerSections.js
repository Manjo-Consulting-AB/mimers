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
 * Issue 57 gör itemlistan till pärmens förstasida och lägger sin rad ovanför
 * den här.
 */
export const containerSections = [
    { key: 'settings', href: (ulid) => `/containers/${ulid}/edit` },
];

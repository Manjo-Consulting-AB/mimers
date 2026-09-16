/*
 * Inställningarnas sektioner, se issue 53b § Beslut 2.
 *
 * Listan är det enda som växer: SettingsLayout renderar den med `v-for`, så
 * en ny sida är en ny rad här och ingen ändring i layouten. 53b lade
 * Säkerhet; issue 53c lade till Profil och Konton, och issue 65a Notiser.
 * Issue 66 Plan lägger sin rad här den också.
 *
 * Profil ligger först: `/settings` omdirigerar dit (routes/web.php), så den
 * posten är inställningarnas förstasida och ska stå överst i listan.
 *
 * `key` är både React-nyckeln och sista ledet i översättningsnyckeln
 * (`settings.nav.<key>` i lang/{locale}/ui.php). Ingen färdig mening här:
 * texten formuleras på servern och slås bara upp på klienten, se
 * [[ADR-0021 Frontendteknik]] och resources/js/composables/useTranslations.js.
 */
export const settingsSections = [
    { key: 'profile', href: '/settings/profile' },
    { key: 'accounts', href: '/settings/accounts' },
    { key: 'notifications', href: '/settings/notifications' },
    { key: 'security', href: '/settings/security' },
];

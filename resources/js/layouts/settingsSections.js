/*
 * Inställningarnas sektioner, se issue 53b § Beslut 2.
 *
 * Listan är det enda som växer: SettingsLayout renderar den med `v-for`, så
 * en ny sida är en ny rad här och ingen ändring i layouten. 53b lade
 * Säkerhet; issue 53c lägger till Profil och Konton. Issue 65 Notiser och
 * issue 66 Plan lägger sina rader här de också.
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
    { key: 'security', href: '/settings/security' },
];

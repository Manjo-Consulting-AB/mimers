/*
 * Inställningarnas sektioner, se issue 53b § Beslut 2.
 *
 * Listan är det enda som växer: SettingsLayout renderar den med `v-for`, så
 * en ny sida är en ny rad här och ingen ändring i layouten. Issue 53c lägger
 * till Profil och Konto, issue 65 Notiser, issue 66 Plan.
 *
 * `key` är både React-nyckeln och sista ledet i översättningsnyckeln
 * (`settings.nav.<key>` i lang/{locale}/ui.php). Ingen färdig mening här:
 * texten formuleras på servern och slås bara upp på klienten, se
 * [[ADR-0021 Frontendteknik]] och resources/js/composables/useTranslations.js.
 */
export const settingsSections = [
    { key: 'security', href: '/settings/security' },
];

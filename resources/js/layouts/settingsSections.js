/*
 * Inställningarnas sektioner, se issue 53b § Beslut 2.
 *
 * Listan är det enda som växer: SettingsLayout renderar den med `v-for`, så
 * en ny sida är en ny rad här och ingen ändring i layouten. 53b lade
 * Säkerhet; issue 53c lade till Profil och Konton, och issue 65a Notiser.
 * Issue 66 Plan lägger sin rad här den också.
 *
 * `plan` kom med issue 66a och ligger efter `accounts`: båda handlar om
 * KONTOT — vad det heter och vad det får — och planen är svaret på den andra
 * frågan. Notiserna och webhookarna under den handlar om vad som lämnar
 * systemet.
 *
 * Profil ligger först: `/settings` omdirigerar dit (routes/web.php), så den
 * posten är inställningarnas förstasida och ska stå överst i listan.
 *
 * `webhooks` kom med issue 65b § Beslut 1 och ligger efter `notifications`:
 * båda handlar om vad som lämnar systemet, men webhookarna hör till KONTOT
 * och inte till personen — de är kontots utgång till egna system, och den
 * som förvaltar dem måste vara `owner` eller `admin`
 * (App\Policies\AccountPolicy::manageWebhooks()).
 *
 * `key` är både React-nyckeln och sista ledet i översättningsnyckeln
 * (`settings.nav.<key>` i lang/{locale}/ui.php). Ingen färdig mening här:
 * texten formuleras på servern och slås bara upp på klienten, se
 * [[ADR-0021 Frontendteknik]] och resources/js/composables/useTranslations.js.
 */
export const settingsSections = [
    { key: 'profile', href: '/settings/profile' },
    { key: 'accounts', href: '/settings/accounts' },
    { key: 'plan', href: '/settings/plan' },
    { key: 'notifications', href: '/settings/notifications' },
    { key: 'webhooks', href: '/settings/webhooks' },
    { key: 'security', href: '/settings/security' },
];

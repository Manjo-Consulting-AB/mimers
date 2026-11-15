import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import * as Sentry from '@sentry/vue';

/*
 * `<html lang>` — se issue 68b § Beslut 9.
 *
 * Rotvyn sätter attributet ur `app()->getLocale()` vid den första
 * sidladdningen (resources/views/app.blade.php), och det räcker så länge
 * språket inte ändras under sessionen. En Inertia-visit ritar om sidan i
 * webbläsaren utan att rotvyn renderas på nytt, och utan raden nedan står
 * det första svaret kvar över nästa sidas innehåll — varje skärmläsare
 * uttalar den då med fel röst.
 *
 * Den delade propen `locale` (issue 52) är samma värde som rotvyn använder,
 * så de två vägarna kan inte glida isär. `inertia:navigate` fyras av varje
 * avslutad visit — också den första, vilket gör den till den enda krok som
 * behövs.
 */
function setDocumentLanguage(locale) {
    if (locale) {
        document.documentElement.lang = locale;
    }
}

document.addEventListener('inertia:navigate', (event) => {
    setDocumentLanguage(event.detail.page.props.locale);
});

/*
 * En ändring kastar allt sparat — se issue 277 § Beslut 2.
 *
 * Cachet bor i Inertias router och överlever inte en omladdning, men det
 * lever längre än en ändring: en förhämtad sida som används ur minnet når
 * aldrig servern, och hade kunnat visa data som ändrats sedan den hämtades.
 * `finish` fyras av varje avslutad visit, och varje visit som inte är GET —
 * POST, PUT, PATCH och DELETE, utloggningen inräknad — tömmer därför hela
 * cachet. Cachetaggar används inte: en tömning per ändring är enkel att lita
 * på, och en felaktig tagg visar gammal data.
 */
router.on('finish', (event) => {
    if (event.detail.visit.method !== 'get') {
        router.flushAll();
    }
});

/*
 * Produktens namn i fliktiteln — se issue 1225.
 *
 * `import.meta.env.VITE_APP_NAME` bakas in i bundlen vid bygget, och värdet
 * kommer ur `.env`, som bara finns lokalt. Artefakten byggs i GitHub Actions
 * utan den filen, så utan reserven blev titeln "To do — undefined" på både
 * staging och i produktionen. Reserven gör titeln rätt i varje bygge, även
 * `ci.yml`.
 */
/*
 * Felrapportering från webbläsaren — se issue 388 och
 * [[ADR-0079 Övervakning]] § 1.
 *
 * Konfigurationen kommer från servern (propen `sentry`), inte från bygget:
 * samma artefakt rullas ut i staging och produktion, så DSN och miljö kan inte
 * bakas in med `VITE_*` (jämför issue 1225). Utan propen — ingen DSN —
 * initieras ingenting.
 *
 * Fel som inte är våra släpps här, och listan står på det här ena stället.
 */
const SENTRY_IGNORED_MESSAGES = [
    /ResizeObserver loop/i,
    /Non-Error promise rejection captured/i,
];

const SENTRY_EXTENSION_SCHEMES = /^(chrome|moz|safari|safari-web)-extension:\/\//;

function isOurOwnError(event) {
    const message = event.message ?? event.exception?.values?.[0]?.value ?? '';

    if (SENTRY_IGNORED_MESSAGES.some((pattern) => pattern.test(message))) {
        return false;
    }

    const frames = (event.exception?.values ?? []).flatMap((value) => value.stacktrace?.frames ?? []);
    const filenames = frames.map((frame) => frame.filename ?? '').filter(Boolean);

    if (filenames.some((name) => SENTRY_EXTENSION_SCHEMES.test(name))) {
        return false;
    }

    // En stack där ingen bildruta kommer från vår egen origin är inte vår kod.
    return filenames.length === 0 || filenames.some((name) => name.startsWith(window.location.origin));
}

function scrubSentryEvent(event) {
    if (! isOurOwnError(event)) {
        return null;
    }

    if (event.request) {
        delete event.request.cookies;
    }

    if (event.user) {
        delete event.user.ip_address;
    }

    return event;
}

// Brödsmulor för navigering och klick, men utan text ur formulärfält.
function scrubSentryBreadcrumb(breadcrumb) {
    if (breadcrumb.category === 'ui.input') {
        return null;
    }

    if (breadcrumb.category === 'ui.click' && breadcrumb.message) {
        // Klickbrödsmulan är en CSS-väg, aldrig fältets värde; formulärfält
        // släpps ändå så att inget skrivet innehåll kan följa med.
        if (/\b(input|textarea|select)\b/i.test(breadcrumb.message)) {
            return null;
        }
    }

    return breadcrumb;
}

function initSentry(app, config) {
    Sentry.init({
        app,
        dsn: config.dsn,
        environment: config.environment,
        release: config.release,
        sendDefaultPii: false,
        tracesSampleRate: 0,
        // Ingen Session Replay: ingen replay-integration läggs till, och
        // nollorna nedan stänger även de förvalda samplingstalen.
        replaysSessionSampleRate: 0,
        replaysOnErrorSampleRate: 0,
        beforeSend: scrubSentryEvent,
        beforeBreadcrumb: scrubSentryBreadcrumb,
        initialScope: {
            user: config.user ? { id: config.user } : null,
        },
    });

    // En inloggning eller utloggning via Inertia byter användare utan omladdning.
    document.addEventListener('inertia:navigate', (event) => {
        const user = event.detail.page.props.sentry?.user;

        Sentry.setUser(user ? { id: user } : null);
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Mimers';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    /*
     * Förhämtningens livslängd — se issue 277 § Beslut 1. En förhämtad sida
     * är färsk i 30 sekunder och används då utan ny förfrågan. Mellan 30
     * sekunder och 5 minuter visas den direkt och hämtas om i bakgrunden
     * (*stale-while-revalidate*); därefter är den borta. Cachet bor i
     * Inertias router, i minnet och per flik, och överlever varken en
     * omladdning eller en utloggning ([[ADR-0021 Frontendteknik]]).
     */
    defaults: {
        prefetch: { cacheFor: ['30s', '5m'] },

        /*
         * Mjuka övergångar mellan sidorna — se issue 279 § Beslut 1.
         *
         * Webbläsarens View Transitions tonar över ett sidbyte i stället för
         * att byta tvärt. Bara GET och bara när tillståndet inte bevaras: en
         * filterrad går med `preserveState: true`, och en lista som tonar för
         * varje tangenttryckning i ett sökfält är värre än ingen övergång.
         * Ändringar (POST, PUT, PATCH, DELETE) tonar inte heller.
         *
         * Funktionen returnerar BARA nyckeln `viewTransition` eller ett tomt
         * objekt. Inertia 3.7 lägger resultatet ovanpå besökets egna val, så en
         * returnerad kopia av `options` hade skrivit över dem.
         */
        visitOptions: (href, options) =>
            (options.method ?? 'get') === 'get' && ! options.preserveState ? { viewTransition: true } : {},
    },

    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });

        return pages[`./pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        setDocumentLanguage(props.initialPage.props.locale);

        const app = createApp({ render: () => h(App, props) });

        if (props.initialPage.props.sentry) {
            initSentry(app, props.initialPage.props.sentry);
        }

        app.use(plugin).mount(el);
    },

    progress: {
        color: '#1d4ed8',
    },
});

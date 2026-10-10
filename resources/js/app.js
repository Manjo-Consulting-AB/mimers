import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

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

createInertiaApp({
    title: (title) => (title ? `${title} — ${import.meta.env.VITE_APP_NAME}` : import.meta.env.VITE_APP_NAME),

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
    },

    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true });

        return pages[`./pages/${name}.vue`];
    },

    setup({ el, App, props, plugin }) {
        setDocumentLanguage(props.initialPage.props.locale);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        color: '#1d4ed8',
    },
});

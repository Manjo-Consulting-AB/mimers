import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

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

createInertiaApp({
    title: (title) => (title ? `${title} — ${import.meta.env.VITE_APP_NAME}` : import.meta.env.VITE_APP_NAME),

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

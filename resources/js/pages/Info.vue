<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Informationssidorna — /about, /privacy, /terms och /subprocessors, se issue
 * 202 och M86 · issue 480. /help har sedan M78 · issue 458 en egen sida,
 * Help.vue.
 *
 * EN sida för fyra adresser: rutten skickar vilken sida det är i `page`-
 * proppen, och rubriken slås upp ur `info.<sida>.title` i
 * lang/{locale}/ui.php. Ingen sträng bor här — samma regel som resten av
 * webben ([[ADR-0021 Frontendteknik]] § Beslut: webbens text formuleras på
 * servern ur lang/).
 *
 * **`body` är sidans text på markdown** (M85 · issue 478). Servern läser
 * `lang/{locale}/pages/{sida}.md` och ritar den med `HelpMarkdown`, så det som
 * kommer hit är redan HTML — [[ADR-0094 Hjälpcentret]] § 4. Saknas filen är
 * `body` tom, och då ritas platshållaren ur `info.placeholder` som förut. Det
 * är läget för /terms tills dess text skrivs.
 *
 * **`<meta name="description">` kommer ur `info.<sida>.description`**
 * (M86 · issue 480). `head-key` gör att Inertia byter ut taggen när man går
 * mellan sidorna i stället för att lägga en andra bredvid.
 *
 * **`v-html` är samma undantag som i Help.vue, Category.vue och ItemNotes.vue**
 * ([[ADR-0066 Itemets anteckning]] § 3). Förbudet i [[ADR-0057 Ikoner och
 * typsnitt]] § 2 gäller ikonernas SVG och står kvar; här ritas bara HTML som
 * servern har ritat enligt § 4, aldrig något användaren skickat, och klienten
 * ritar aldrig markdown själv. Klassen `help-content` ger typografin ur
 * resources/css/app.css.
 *
 * Sidan ritas i AppLayout som Welcome.vue och är publik — både en gäst och en
 * inloggad når den.
 */
defineProps({
    page: { type: String, required: true },
    body: { type: String, default: null },
});

const { t } = useTranslations();
</script>

<template>
    <Head :title="t(`info.${page}.title`)">
        <meta
            name="description"
            :content="t(`info.${page}.description`)"
            head-key="description"
        >
    </Head>

    <div class="py-12 text-center">
        <h1 class="text-3xl font-semibold">{{ t(`info.${page}.title`) }}</h1>

        <div
            v-if="body"
            class="help-content mx-auto mt-6 max-w-prose text-left"
            v-html="body"
        />

        <p v-else class="mt-3 text-slate-600">{{ t('info.placeholder') }}</p>
    </div>
</template>

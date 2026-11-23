<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Hjälpsidan, /help — M78 · issue 458.
 *
 * Texterna är markdownfiler under lang/{locale}/help/ och ritas till HTML på
 * servern av App\Actions\Help\LoadHelpArticles. Sidan får en lista kategorier
 * i `categories`; varje kategori har en `slug`, en titel, en eventuell ingress
 * och frågor med ett eget ankare, så att en fråga kan länkas som
 * `/help#how-do-i-share-a-container`. Inertia scrollar själv till ankaret.
 *
 * **Det andra undantaget från regeln om `v-html`**, efter itemets anteckning
 * (ItemNotes.vue, [[ADR-0066 Itemets anteckning]] § 2). Det är säkert av
 * samma skäl: HTML:en ritas på servern ur filer i repot med
 * `html_input => 'strip'` och kommer aldrig från användaren. Klienten ritar
 * aldrig markdown själv.
 *
 * Sidans egna ord (rubrik, undertitel, innehåll) slås upp ur `info.help.*` i
 * lang/{locale}/ui.php ([[ADR-0021 Frontendteknik]] § Beslut).
 */
defineProps({
    categories: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <Head :title="t('info.help.title')" />

    <div class="mx-auto max-w-3xl py-12">
        <h1 class="text-3xl font-semibold">{{ t('info.help.title') }}</h1>
        <p class="mt-3 text-slate-600">{{ t('info.help.subtitle') }}</p>

        <nav class="mt-8" :aria-label="t('info.help.contents')">
            <h2 class="text-lg font-semibold">{{ t('info.help.contents') }}</h2>
            <ul class="mt-2 space-y-1">
                <li v-for="category in categories" :key="category.slug">
                    <a :href="`#${category.slug}`" class="text-accent underline">{{ category.title }}</a>
                </li>
            </ul>
        </nav>

        <section v-for="category in categories" :id="category.slug" :key="category.slug" class="mt-12">
            <h2 class="text-2xl font-semibold">{{ category.title }}</h2>

            <div v-if="category.intro_html" class="help-content mt-3" v-html="category.intro_html" />

            <div v-for="question in category.questions" :key="question.anchor" class="mt-8">
                <h3 :id="question.anchor" class="text-lg font-semibold">{{ question.question }}</h3>
                <div class="help-content mt-2" v-html="question.answer_html" />
            </div>
        </section>
    </div>
</template>

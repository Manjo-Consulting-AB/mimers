<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import HelpFeedback from '../../components/HelpFeedback.vue';
import HelpSearchBox from '../../components/HelpSearchBox.vue';
import { formatLocaleDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Artikelsidan, /help/articles/{id} — M80 · issue 464, se
 * [[ADR-0094 Hjälpcentret]] § 3, § 4 och § 10.
 *
 * Varje artikel — också varje fråga — har en egen sida med brödsmula, rubrik,
 * typ, datum, innehåll och, när kategorin har fler artiklar, föregående och
 * nästa. `related` är artikelns egna, i front matterns ordning; servern har
 * redan silat bort det som inte syns.
 *
 * **Innehållsförteckningen ritas bara för längre artiklar.** Servern skickar
 * bara rubrikerna på nivå 2, och `headings` har minst tre poster först när
 * artikeln har minst tre sådana rubriker. En tvårubriksartikel får ingen rad
 * som bara upprepar det läsaren ser — tröskeln är tre och står här, en gång.
 *
 * **Videon laddar YouTube först vid klick.** HelpMarkdown ritar rutan som en
 * `<figure class="help-video" data-youtube-id="…">` med en vanlig länk
 * (`HelpVideoRenderer`). Lyssaren på innehållsytan fångar klicket på
 * `.help-video-start`, kontrollerar id:t mot YouTubes eget mönster och byter
 * figurens innehåll mot en `iframe` mot `youtube-nocookie.com` — byggd med
 * `document.createElement`, aldrig ur en HTML-sträng. Fram till klicket
 * kontaktas ingen tredje part och sätts ingen kaka (ADR-0094 § 4). Lyssaren
 * läggs på elementet i `onMounted` i stället för som `@click` i mallen: ytan
 * som går att klicka på är länken, och en `<div>` med `@click` hade varit en
 * knapp ingen når med tabb.
 *
 * **`v-html` här är samma undantag som i Help.vue och ItemNotes.vue**
 * ([[ADR-0066 Itemets anteckning]] § 3, [[ADR-0094 Hjälpcentret]] § 4). Det är
 * säkert av samma skäl: HTML:en ritas på servern ur filer i repot med
 * `html_input => 'strip'` och kommer aldrig från användaren. Klienten ritar
 * aldrig markdown själv.
 *
 * **Ankare.** Rubrikerna i innehållet har id:n ur HelpMarkdown, så en adress
 * med `#ankare` scrollas fram vid laddning — och vid `hashchange`, så att en
 * länk inom sidan gör samma sak. Samma form som på kategorisidan.
 *
 * **Datumet följer datumregeln** (issue 104): `updated` är en ISO-dag, och
 * `formatLocaleDate()` ur komposabeln skriver den i användarens locale. Dagen
 * byggs som lokal midnatt — en DATE har ingen tidszon att räknas om över, och
 * `new Date("2026-11-22")` hade varit UTC-midnatt och kunnat visa dagen före.
 *
 * Sidans egna ord slås upp ur `help.*` i lang/{locale}/ui.php
 * ([[ADR-0021 Frontendteknik]] § Beslut). Artikelns och kategorins ord kommer
 * ur innehållet.
 */
const props = defineProps({
    article: { type: Object, required: true },
    category: { type: Object, required: true },
    related: { type: Array, required: true },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
});

const { t } = useTranslations();
const locale = computed(() => usePage().props.locale);

const updated = computed(() => formatLocaleDate(
    new Date(`${props.article.updated}T00:00:00`),
    locale.value,
));

const content = ref(null);

let contentElement = null;

/** YouTube-id:t är elva tecken ur `[A-Za-z0-9_-]`, som HelpVideo ritar det. */
const YOUTUBE_ID = /^[A-Za-z0-9_-]{11}$/;

function startVideo(event) {
    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    const start = target.closest('.help-video-start');

    if (!(start instanceof HTMLElement)) {
        return;
    }

    const figure = start.closest('.help-video');

    if (!(figure instanceof HTMLElement)) {
        return;
    }

    const id = figure.dataset.youtubeId;

    /*
     * Ett id som inte är YouTubes eget går vidare som en vanlig länk: då
     * öppnas videon på youtube.com i en ny flik, och den trasiga rutan blir
     * aldrig en iframe mot en adress vi inte känner.
     */
    if (typeof id !== 'string' || !YOUTUBE_ID.test(id)) {
        return;
    }

    event.preventDefault();

    const iframe = document.createElement('iframe');

    iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1`;
    iframe.title = start.textContent?.trim() ?? '';
    iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
    iframe.allowFullscreen = true;

    figure.replaceChildren(iframe);
}

function scrollToHash() {
    let id;

    /*
     * En trasig hash (`#%E0%A4%A`) kastar URIError. Det ska inte avbryta
     * monteringen eller `hashchange`-lyssnaren, så hashen hoppas över.
     */
    try {
        id = decodeURIComponent(window.location.hash.replace(/^#/, ''));
    } catch {
        return;
    }

    if (!id) {
        return;
    }

    document.getElementById(id)?.scrollIntoView();
}

onMounted(() => {
    contentElement = content.value;
    contentElement?.addEventListener('click', startVideo);

    scrollToHash();
    window.addEventListener('hashchange', scrollToHash);
});

onUnmounted(() => {
    contentElement?.removeEventListener('click', startVideo);
    contentElement = null;

    window.removeEventListener('hashchange', scrollToHash);
});
</script>

<template>
    <Head :title="article.title" />

    <div class="mx-auto max-w-3xl py-12">
        <nav :aria-label="t('info.help.title')" class="mb-4 text-sm text-ink-muted">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <li class="flex items-center gap-2">
                    <Link
                        href="/help"
                        class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                    >
                        {{ t('help.breadcrumb.home') }}
                    </Link>
                    <span aria-hidden="true">›</span>
                </li>
                <li class="flex items-center gap-2">
                    <Link
                        :href="`/help/${category.id}`"
                        class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                    >
                        {{ category.title }}
                    </Link>
                    <span aria-hidden="true">›</span>
                </li>
                <li aria-current="page" class="flex min-h-11 items-center">{{ article.title }}</li>
            </ol>
        </nav>

        <!-- Sökrutan i mindre form — M81 · issue 467. Den står under
             brödsmulan och över rubriken, och söker i hela hjälpen. -->
        <div class="mb-6">
            <HelpSearchBox size="compact" />
        </div>

        <h1 class="text-3xl font-semibold">{{ article.title }}</h1>

        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-muted">
            <span class="rounded-control border border-border px-2 py-0.5">
                {{ t(`help.types.${article.type}`) }}
            </span>
            <span>{{ t('help.updated', { date: updated }) }}</span>
        </p>

        <nav
            v-if="article.headings.length >= 3"
            :aria-label="t('help.contents')"
            class="mt-8 rounded-card border border-border bg-surface p-4"
        >
            <h2 class="text-title font-semibold">{{ t('help.contents') }}</h2>
            <ol class="mt-2 space-y-1">
                <li v-for="heading in article.headings" :key="heading.id">
                    <a
                        :href="`#${heading.id}`"
                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                    >
                        {{ heading.title }}
                    </a>
                </li>
            </ol>
        </nav>

        <div ref="content" class="help-content mt-6" v-html="article.html" />

        <section v-if="related.length" class="mt-10">
            <h2 class="text-title font-semibold">{{ t('help.related') }}</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="item in related" :key="item.id">
                    <Link
                        :href="`/help/articles/${item.id}`"
                        class="flex min-h-11 flex-wrap items-center gap-2 rounded-control border border-border bg-surface px-4 py-3 outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        <span class="font-medium text-ink">{{ item.title }}</span>
                        <span class="text-sm text-ink-muted">{{ t(`help.types.${item.type}`) }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!-- *Was this helpful?* — M82 · issue 471, ADR-0094 § 7. Sist i
             innehållet, före Previous/Next. -->
        <HelpFeedback :article-id="article.id" class="mt-10" />

        <nav
            v-if="previous || next"
            :aria-label="article.title"
            class="mt-10 flex flex-wrap justify-between gap-3"
        >
            <Link
                v-if="previous"
                :href="`/help/articles/${previous.id}`"
                class="inline-flex min-h-11 flex-col justify-center rounded-control border border-border bg-surface px-4 py-3 outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <span class="text-sm text-ink-muted">{{ t('help.previous') }}</span>
                <span class="font-medium text-ink">{{ previous.title }}</span>
            </Link>
            <Link
                v-if="next"
                :href="`/help/articles/${next.id}`"
                class="ml-auto inline-flex min-h-11 flex-col justify-center rounded-control border border-border bg-surface px-4 py-3 text-right outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <span class="text-sm text-ink-muted">{{ t('help.next') }}</span>
                <span class="font-medium text-ink">{{ next.title }}</span>
            </Link>
        </nav>
    </div>
</template>

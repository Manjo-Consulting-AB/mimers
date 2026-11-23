<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Kategorisidan, /help/{kategori} — M80 · issue 462, se
 * [[ADR-0094 Hjälpcentret]] § 3.
 *
 * Frågorna (`faq`) ritas som utfällbara rader: `<details>` med artikelns id
 * som ankare, så en fråga går att länka som `/help/{kategori}#{id}`. Vid
 * laddning — och vid `hashchange`, så att en länk inom sidan gör samma sak —
 * öppnas den rad som matchar `location.hash` och skrollas fram. Övriga typer
 * (`guide`, `reference`, `troubleshooting`) är en lista med länkar till
 * `/help/articles/{id}`, som 464 bygger; det finns inga sådana artiklar än, så
 * delen syns inte förrän innehållet kommer.
 *
 * **`v-html` här är samma undantag som i Help.vue och ItemNotes.vue**
 * ([[ADR-0066 Itemets anteckning]] § 3, [[ADR-0094 Hjälpcentret]] § 4). Det är
 * säkert av samma skäl: HTML:en ritas på servern ur filer i repot med
 * `html_input => 'strip'` och kommer aldrig från användaren. Klienten ritar
 * aldrig markdown själv.
 *
 * Sidans egna ord slås upp ur `help.*` i lang/{locale}/ui.php
 * ([[ADR-0021 Frontendteknik]] § Beslut). Kategorins och frågornas ord kommer
 * ur innehållet.
 */
const props = defineProps({
    category: { type: Object, required: true },
    articles: { type: Array, required: true },
});

const { t } = useTranslations();

const faq = computed(() => props.articles.filter((article) => article.type === 'faq'));
const others = computed(() => props.articles.filter((article) => article.type !== 'faq'));

/** Id:t på den fråga vars länk senast kopierades, annars null. */
const copied = ref(null);

function openFromHash() {
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

    const target = document.getElementById(id);

    if (!(target instanceof HTMLDetailsElement)) {
        return;
    }

    target.open = true;
    target.scrollIntoView();
}

onMounted(() => {
    openFromHash();
    window.addEventListener('hashchange', openFromHash);
});

onUnmounted(() => {
    window.removeEventListener('hashchange', openFromHash);
});

function copyLink(article) {
    if (!navigator.clipboard) {
        return;
    }

    const address = `${window.location.origin}/help/${props.category.id}#${article.id}`;

    navigator.clipboard.writeText(address).then(() => {
        copied.value = article.id;
    });
}
</script>

<template>
    <Head :title="category.title" />

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
                <li aria-current="page" class="flex min-h-11 items-center">{{ category.title }}</li>
            </ol>
        </nav>

        <h1 class="text-3xl font-semibold">{{ category.title }}</h1>
        <p class="mt-3 text-slate-600">{{ category.description }}</p>

        <div v-if="category.intro_html" class="help-content mt-4" v-html="category.intro_html" />

        <section class="mt-8 space-y-3">
            <details
                v-for="article in faq"
                :id="article.id"
                :key="article.id"
                class="help-faq rounded-card border border-border bg-surface"
            >
                <summary
                    class="flex min-h-11 cursor-pointer items-center justify-between gap-3 px-4 text-title text-ink"
                >
                    {{ article.title }}
                </summary>

                <div class="px-4 pb-4">
                    <div class="help-content" v-html="article.answer_html" />

                    <button
                        type="button"
                        class="mt-3 inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline"
                        @click="copyLink(article)"
                    >
                        {{ copied === article.id ? t('help.link_copied') : t('help.copy_link') }}
                    </button>
                </div>
            </details>
        </section>

        <section v-if="others.length" class="mt-10">
            <ul class="mt-3 space-y-2">
                <li v-for="article in others" :key="article.id">
                    <Link
                        :href="`/help/articles/${article.id}`"
                        class="flex min-h-11 flex-col rounded-control border border-border bg-surface px-4 py-3 outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        <span class="text-title font-medium text-ink">{{ article.title }}</span>
                        <span v-if="article.description" class="text-sm text-ink-muted">
                            {{ article.description }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>

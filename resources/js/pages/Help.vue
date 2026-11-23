<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import UiIcon from '../components/UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Hjälpcentrets startsida, /help — M78 · issue 458, M80 · issue 461, 462
 * och 465.
 *
 * Sidan är hjälpcentrets framsida: rubriken, platsen för sökningen (467),
 * *Popular articles* ur `featured` och kategorierna som kort med ikon,
 * beskrivning, antal och de tre första frågorna. Den fungerar på mobil först:
 * ett rutnät som är en kolumn under `sm`, två under `lg` och tre därefter
 * ([[ADR-0042 Designsystemet]] § Beslut).
 *
 * **Hela kortet är ingen länk.** Ett kort som är en länk och samtidigt bär
 * frågelänkar hade gett länkar inuti en länk — ogiltig HTML och en yta där
 * webbläsaren själv väljer vilken länk som vinner. Rubriken är kortets väg
 * till kategorisidan och frågorna ligger bredvid (issue 465).
 *
 * **De gamla ankarna överlever här.** Texterna har tidigare legat på en enda
 * sida, så `/help#how-do-i-share-a-container` och `/help#sharing` är adresser
 * som kan stå i ett bokmärke. Fragmentet når aldrig servern och kan därför
 * inte skickas vidare i en omdirigering — i stället skickar servern kartan
 * `anchors`, varje ankare → sin adress, och sidan slår upp `location.hash` i
 * den vid laddning och ber Inertia besöka adressen med `replace: true`
 * ([[ADR-0094 Hjälpcentret]] § 3).
 *
 * Sidans egna ord slås upp ur `help.*` i lang/{locale}/ui.php
 * ([[ADR-0021 Frontendteknik]] § Beslut). Kategoriernas titlar och
 * beskrivningar kommer ur innehållet, inte ur lang/.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    /* Artiklarna med `featured: true`, högst sex; tom när ingen är märkt. */
    featured: { type: Array, required: true },
    anchors: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Kategori → ikonroll i den slutna listan i resources/js/icons/index.js
 * ([[ADR-0057 Ikoner och typsnitt]] § 1). Rollen är en roll och inte
 * ikonens namn, och listan är sluten: hittar vi ingen passande roll för en
 * kategori används standardrollen i stället för att lägga till en ikon.
 */
const categoryIcons = {
    'getting-started': 'tip',
    'containers-and-items': 'container',
    tasks: 'tasks',
    'files-and-storage': 'documents',
    sharing: 'share',
    plans: 'cost',
    'account-and-security': 'settings',
    'privacy-and-data': 'lock',
};

const defaultCategoryIcon = 'category';

onMounted(() => {
    const anchor = window.location.hash.slice(1);

    if (!anchor) {
        return;
    }

    /*
     * Bara kartans egna poster. `props.anchors[anchor]` hade annars gett en
     * ärvd funktion för `#constructor`, `#toString` och `#__proto__`, och
     * `router.visit(<funktion>)` kastar i stället för att lämna sidan i fred.
     */
    if (!Object.hasOwn(props.anchors, anchor)) {
        return;
    }

    const address = props.anchors[anchor];

    if (typeof address !== 'string') {
        return;
    }

    router.visit(address, { replace: true });
});

/*
 * Antalet frågor. `t()` pluraliserar inte — translate.js byter bara ut
 * `:param` — så den tvågrenade formen ur `help.questions_count` delas här:
 * första grenen för ett, andra för övriga. Saknas nyckeln returnerar `t()`
 * nyckeln själv, och då finns ingen andra gren att välja.
 */
function questionsLabel(count) {
    const [one, many] = t('help.questions_count').split('|');

    return (count === 1 ? one : (many ?? one)).replace(':count', String(count));
}
</script>

<template>
    <Head :title="t('info.help.title')" />

    <div class="mx-auto max-w-5xl py-12">
        <h1 class="text-display font-semibold">{{ t('help.home.title') }}</h1>
        <p class="mt-3 text-ink-muted">{{ t('help.home.subtitle') }}</p>

        <div><!-- Sökrutan, issue 467 --></div>

        <!--
            Populära artiklar (issue 465). Rubriken ritas bara när listan har
            något — en rubrik över en tom lista är en yta som påstår att det
            finns något att visa.
        -->
        <section v-if="featured.length" class="mt-10">
            <h2 class="text-heading font-semibold text-ink">{{ t('help.home.popular') }}</h2>

            <ul class="mt-4 space-y-1">
                <li v-for="article in featured" :key="article.id">
                    <Link
                        :href="`/help/articles/${article.id}`"
                        class="inline-flex min-h-11 items-center gap-2 text-ink outline-none hover:text-accent focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        <span>{{ article.title }}</span>
                        <span class="text-meta text-ink-subtle">{{ article.category_title }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <!--
            Kategorikorten (issue 465). En kolumn under `sm`, två under `lg`
            och tre därefter. Rubriken är kortets enda länk; frågorna ligger
            bredvid och är egna länkar.
        -->
        <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="category in categories"
                :key="category.id"
                class="flex flex-col rounded-card border border-border bg-surface p-4"
            >
                <div class="flex items-start gap-3">
                    <UiIcon
                        :name="categoryIcons[category.id] ?? defaultCategoryIcon"
                        :size="24"
                        tile="accent"
                    />

                    <div class="min-w-0">
                        <h2 class="text-title font-semibold">
                            <Link
                                :href="`/help/${category.id}`"
                                class="inline-flex min-h-11 items-center text-ink outline-none hover:text-accent focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            >
                                {{ category.title }}
                            </Link>
                        </h2>
                        <p class="text-sm text-ink-muted">{{ category.description }}</p>
                        <p class="text-meta text-ink-subtle">{{ questionsLabel(category.count) }}</p>
                    </div>
                </div>

                <ul v-if="category.top.length" class="mt-3 space-y-1">
                    <li v-for="question in category.top" :key="question.id">
                        <Link
                            :href="`/help/articles/${question.id}`"
                            class="inline-flex min-h-11 items-center text-sm text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            {{ question.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

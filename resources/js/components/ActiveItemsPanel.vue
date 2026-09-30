<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *Senaste aktiva items* på historikfliken, se issue 180 ·
 * [[ADR-0050 Desktopdesignen]] § 17 och docs/Design/Historik.png.
 *
 * **Panelen ställer ingen fråga.** Posterna kommer färdigräknade ur
 * App\Actions\Audit\ListAuditEvents::statsForContainer() — de fem items med
 * flest händelser, redan avgränsade till omfånget och utan papperskorgen —
 * och den här filen sorterar inte om och räknar ingen grupp. En panel med en
 * egen fråga hade varit den andra sanningen om vad användaren får se
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Stapelns längd är en ritregel och ingen summa.** Den mest aktiva itemen
 * sätter taket, och de övriga mäts mot den; talet står som text bredvid, så
 * en läsare aldrig behöver mäta en stapel.
 *
 * **Ramen är `UiCard`** (issue 99): rubriken är kortets rubrikrad och länken
 * sitter i kortets åtgärdsplats — samma form som containerns uppgiftspanel.
 * Raden är en `<li>` med en `<Link>`, så träffytan är 44 px (issue 68a
 * § Beslut 3) och målet är itemets egen sida.
 *
 * Ingen sträng står i filen (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, länkens ord och varje rads tal kommer ur `t()`.
 */
const props = defineProps({
    /* Containerns ULID — målets adress byggs ur den. */
    containerUlid: { type: String, required: true },
    /* Itemerna i fallande antal: `[{ulid, name, count}]`, högst fem. */
    items: { type: Array, required: true },
});

const { t } = useTranslations();

const itemsUrl = computed(() => `/containers/${props.containerUlid}/items`);

/* Taket för staplarna: den mest aktiva itemen. Aldrig 0 (division med noll). */
const peak = computed(() => Math.max(1, ...props.items.map((item) => item.count)));
</script>

<template>
    <UiCard>
        <template #heading>{{ t('audit.history.recent_items') }}</template>

        <template #action>
            <!--
                Samma ord som containerns översikt använder för samma åtgärd:
                *View all* är listans väg vidare, och en egen kopia här hade
                varit den andra sanningen om vad länken heter.
            -->
            <Link :href="itemsUrl" class="inline-flex min-h-11 items-center text-accent hover:underline">
                {{ t('container.overview.view_all') }}
            </Link>
        </template>

        <ul class="flex flex-col divide-y divide-border">
            <li v-for="item in props.items" :key="item.ulid">
                <Link :href="`${itemsUrl}/${item.ulid}`" class="flex min-h-11 items-center gap-3">
                    <span class="min-w-0 truncate text-body text-ink">{{ item.name }}</span>

                    <!-- Stapeln är dekorativ: talet står som text bredvid. -->
                    <span class="ml-auto h-1.5 w-24 shrink-0 rounded-pill bg-surface-sunken" aria-hidden="true">
                        <span
                            class="block h-full rounded-pill bg-accent"
                            :style="{ width: `${Math.round((item.count / peak) * 100)}%` }"
                        />
                    </span>

                    <span class="shrink-0 text-meta text-ink-subtle">
                        {{ t('audit.history.day_count', { count: item.count }) }}
                    </span>
                </Link>
            </li>
        </ul>
    </UiCard>
</template>

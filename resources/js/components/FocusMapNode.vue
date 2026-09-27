<script setup>
import { Link } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En nod i fokuskartan, se resources/js/components/FocusMap.vue, issue 156 ·
 * [[M23 Mobilen och kartan]] § 156 och [[ADR-0048 Mobilen och plusknappen]]
 * § 4.
 *
 * **En nod är en länk till sitt item.** Det är hela navigeringen: kartan ritar
 * de närmaste grannarna, och ett tryck på en nod går till den. Noden i mitten
 * är undantaget — den ÄR itemet man står på — och ritas därför utan `href`,
 * med `aria-current="page"` i stället; en länk till sidan man står på är inget
 * mål.
 *
 * **Etiketten är itemets namn, som SYNLIG text.** Noden är en prick, ett namn
 * och ett slag, som i bild 6, och namnet är det en skärmläsare läser upp:
 * länkens tillgängliga namn är texten i den, så ingen `aria-label` behövs och
 * ingen etikett kan glida ifrån namnet den beskriver. Ett långt namn klipps av
 * i ytan — `truncate` — och bär hela namnet i `title`; det tillgängliga namnet
 * är det fulla, för texten är den.
 *
 * **Slagets ord och prick kommer som proppar** och slås inte upp här: orden är
 * anroparens (`FocusMap` äger både noderna och teckenförklaringen, och de två
 * ska säga samma sak), och en form som hittade på egna ord hade varit en andra
 * källa till samma text. Det är samma delning som `UiTabs` gör med flikens
 * etikett.
 *
 * **Plusknappen är nodens EGEN meny** (§ 156): den öppnar samma ark som
 * skalets plusknapp, med de rader anroparen får använda på den här noden.
 * Raderna kommer färdigprövade i `node.rows` — servern äger grinden — och en
 * nod utan rader ritar ingen knapp alls ([[ADR-0048 Mobilen och plusknappen]]
 * § 2: blir menyn tom visas inte knappen). Knappen ritar ett plustecken och
 * bär därför ett `aria-label` som namnger både handlingen och noden.
 *
 * Träffytorna är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och fokusringen
 * får aldrig tas bort ([[ADR-0042 Designsystemet]] § Beslut): `outline-none`
 * utan ringen hade lämnat den som tabbar utan synligt fokus.
 */
defineProps({
    /* Noden: `{ulid, name, href, rows}`. `href` är null för itemet självt. */
    node: { type: Object, required: true },
    /* Slagets ord, färdigöversatt av anroparen. */
    label: { type: String, required: true },
    /* Slagets prick, som klass — tom för itemet självt, som bär kant i stället. */
    dot: { type: String, default: '' },
    /* Sant för itemet man står på: noden i kartans mitt. */
    current: { type: Boolean, default: false },
});

const emit = defineEmits(['open-menu']);

const { t } = useTranslations();

/*
 * Menyn öppnas av den som äger arket, och noden skickar med tre saker: sina
 * rader, sitt eget element — arket fäster under det över `md:` och lämnar
 * tillbaka fokus till det när det stängs — och sitt namn, så att arket säger
 * vilken nod menyn hör till.
 */
</script>

<template>
    <li class="flex w-20 min-w-0 shrink-0 flex-col items-center gap-1">
        <Link
            v-if="node.href"
            :href="node.href"
            class="flex min-h-11 w-full min-w-0 flex-col items-center justify-center gap-0.5 rounded-control border border-border bg-surface px-1 py-1 text-center outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <span v-if="dot" aria-hidden="true" class="h-2.5 w-2.5 rounded-pill" :class="dot"></span>
            <span class="w-full truncate text-meta font-medium text-ink" :title="node.name">{{ node.name }}</span>
            <span class="text-meta text-ink-subtle">{{ label }}</span>
        </Link>

        <span
            v-else
            aria-current="page"
            class="flex min-h-11 w-full min-w-0 flex-col items-center justify-center gap-0.5 rounded-control border border-accent bg-accent-soft px-1 py-1 text-center"
        >
            <span class="w-full truncate text-meta font-medium text-ink" :title="node.name">{{ node.name }}</span>
            <span class="text-meta text-ink-subtle">{{ label }}</span>
        </span>

        <button
            v-if="node.rows.length > 0"
            type="button"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-pill text-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            :aria-label="t('item.map.node_menu', { name: node.name })"
            @click="emit('open-menu', { rows: node.rows, trigger: $event.currentTarget, name: node.name })"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-5 w-5"
                aria-hidden="true"
            >
                <path d="M12 5v14"></path>
                <path d="M5 12h14"></path>
            </svg>
        </button>
    </li>
</template>

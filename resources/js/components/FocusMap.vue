<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import CreateMenu from './CreateMenu.vue';
import FocusMapNode from './FocusMapNode.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Fokuskartan, se issue 156 · [[M23 Mobilen och kartan]] § 156 och
 * [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Itemet ritas i mitten, föräldrarna ovanför, barnen under och de
 * relaterade på sidorna** — tre rader och två sidor, som i bild 6. Trädet
 * till vänster är en annan yta: det visar hela containern, kartan visar
 * itemets närmaste omgivning.
 *
 * **Datan är relationerna och ingen egen fråga.** `map` är
 * App\Actions\Item\ListItemLinks svar uppdelat i sina tre grupper, med en
 * meny per nod — servern har prövat varje nods policyer och skickat med de
 * rader anroparen får använda på den. Komponenten vandrar inte i grafen,
 * sorterar inte om en grupp och räknar ingenting: en nod som ligger utanför
 * anroparens omfång finns inte i svaret, och det finns därför ingen `v-if` här
 * som gömmer något (issue 73 § Beslut 7).
 *
 * **Layouten är FAST, inte beräknad.** Antalet noder per rad är ett tal och
 * inte en mätning: samma karta ritas på en telefon och i högerpanelen, och en
 * rad som räknade ut sitt utrymme hade krävt en webbläsare för att bli rätt —
 * och två svar på samma fråga. Fler noder än som får plats visas som *+N till*
 * i stället, och den raden leder till relationsfliken, som är listan över
 * alla. Ingen horisontell skrollning någonstans: en karta som skrollar i
 * sidled är en karta man inte ser.
 *
 * **`md:` är den enda brytpunkten** (issue 68a § Beslut 2), och den rör
 * kartans PLATS och inte dess form: under `md:` ligger den i itemets
 * relationsflik som läget *Fokus*, över `md:` fyller den högerpanelen. Formen
 * är densamma, och därför bor den här filen på båda ställena.
 *
 * **Plusknappen öppnar samma ark som skalets knapp** ([[ADR-0048 Mobilen och
 * plusknappen]] § 2): `CreateMenu`, med nodens rader och nodens namn som
 * rubrik — menyn säger vilken nod den hör till, och raderna är de samma som i
 * skalet. Är raderna tomma ritas ingen knapp, och arket finns därför bara när
 * det har något att visa. Öppna-läget bor här, som skalets meny bor i
 * AppLayout: noden är en knapp, arket en yta, och den som äger båda är den
 * komponent som ritar dem.
 *
 * **Teckenförklaringen har tre sorter** — *Parent*, *Child* och *Related* —
 * och orden kommer ur `lang/` som all annan text ([[ADR-0013 Språk och
 * i18n]]). Prickens färg kommer ur en roll och inte ur en färgkod
 * ([[ADR-0042 Designsystemet]] § Beslut), och den bär aldrig slaget ensam:
 * ordet står både i teckenförklaringen och under namnet i varje nod.
 */
const props = defineProps({
    /*
     * Kartan: `{self, parent, child, related}`. Varje nod är
     * `{ulid, name, href, rows}` — `href` är null för `self`, som är itemet
     * självt, och `rows` är nodens menyrader (`[{key, href}]`, tom när inget
     * får skapas på den).
     */
    map: { type: Object, required: true },
    /* Relationsfliken — målet för *+N till*. */
    overflowHref: { type: String, required: true },
});

const { t } = useTranslations();

/*
 * Taket per rad, och per sida för de relaterade. Tre noder på en rad och två
 * per sida är vad den fasta ytan ritar; resten blir *+N till*.
 */
const PARENT_LIMIT = 3;
const CHILD_LIMIT = 3;
const RELATED_PER_SIDE = 2;

const parents = computed(() => props.map.parent.slice(0, PARENT_LIMIT));
const children = computed(() => props.map.child.slice(0, CHILD_LIMIT));

/*
 * De relaterade fördelas på två sidor i tur och ordning: de första till
 * vänster, de nästa till höger. Ordningen är serverns — motpartens namn
 * stigande — och den delas av, aldrig sorteras om: "den första" ska vara
 * samma nod i kartan som i relationsfliken.
 */
const relatedLeft = computed(() => props.map.related.slice(0, RELATED_PER_SIDE));
const relatedRight = computed(
    () => props.map.related.slice(RELATED_PER_SIDE, RELATED_PER_SIDE * 2),
);

/*
 * Hur många noder som inte fick plats, per rad. Noll betyder att raden ritar
 * allt den har och ingen *+N till*-rad finns — en nolla är inget påstående om
 * något som dolts, för ingenting är dolt.
 */
const moreParents = computed(() => Math.max(0, props.map.parent.length - PARENT_LIMIT));
const moreChildren = computed(() => Math.max(0, props.map.child.length - CHILD_LIMIT));
const moreRelated = computed(
    () => Math.max(0, props.map.related.length - RELATED_PER_SIDE * 2),
);

/* Slagen i teckenförklaringens ordning, och samma tre noderna ritas i. */
const kinds = ['parent', 'child', 'related'];

/*
 * Slagens prickar. `self` har ingen: noden i mitten bär accentens kant och
 * ordet *Current item* under sitt namn, och en prick hade pekat på ett slag
 * den inte är.
 */
const DOTS = {
    parent: 'bg-accent',
    child: 'bg-success',
    related: 'bg-warning',
};

/*
 * Nodmenyn: raderna, elementet som öppnade den och nodens namn. Arket
 * fäster under elementet över `md:` och lämnar tillbaka fokus till det när det
 * stängs, och rubriken säger vilken nod menyn hör till — raderna under den är
 * relativa till just den noden.
 */
const menuOpen = ref(false);
const menuTrigger = ref(null);
const menuRows = ref([]);
const menuName = ref('');

function openMenu({ rows, trigger, name }) {
    menuRows.value = rows;
    menuTrigger.value = trigger;
    menuName.value = name;
    menuOpen.value = true;
}

function closeMenu() {
    menuOpen.value = false;
}
</script>

<template>
    <div>
        <!--
            Föräldrarna ovanför. Linjen ned till mitten ritas bara när raden
            har något att knyta ihop: en kant mot ingenting är en kant ingen
            förstår.
        -->
        <ul v-if="parents.length > 0" class="flex flex-wrap justify-center gap-2">
            <FocusMapNode
                v-for="node in parents"
                :key="node.ulid"
                :node="node"
                :label="t('item.map.kind.parent')"
                :dot="DOTS.parent"
                @open-menu="openMenu"
            />
        </ul>

        <Link
            v-if="moreParents > 0"
            :href="overflowHref"
            class="mx-auto mt-2 flex min-h-11 w-fit items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
        >
            {{ t('item.map.more', { count: moreParents }) }}
        </Link>

        <div v-if="parents.length > 0" aria-hidden="true" class="mx-auto h-4 w-px bg-border"></div>

        <!--
            Mitten: de relaterade på var sin sida om itemet. BÅDA kolumnerna
            ritas även när en sida är tom — de är lika breda och håller itemet
            i mitten; en kolumn som försvann med sitt innehåll hade flyttat
            mittnoden så snart en sida saknade grannar.
        -->
        <div class="flex items-center justify-center">
            <div class="flex min-w-0 flex-1 flex-col items-end gap-2">
                <FocusMapNode
                    v-for="node in relatedLeft"
                    :key="node.ulid"
                    :node="node"
                    :label="t('item.map.kind.related')"
                    :dot="DOTS.related"
                    @open-menu="openMenu"
                />
            </div>

            <div
                v-if="relatedLeft.length > 0"
                aria-hidden="true"
                class="h-px w-3 shrink-0 bg-border"
            ></div>

            <FocusMapNode
                :node="map.self"
                :label="t('item.links.current')"
                current
                @open-menu="openMenu"
            />

            <div
                v-if="relatedRight.length > 0"
                aria-hidden="true"
                class="h-px w-3 shrink-0 bg-border"
            ></div>

            <!--
                De relaterade är fler än två: *+N till* står sist i den högra
                kolumnen, i samma flöde som noderna den räknar, så att raden
                inte kan läsas som barnens.
            -->
            <div class="flex min-w-0 flex-1 flex-col items-start gap-2">
                <FocusMapNode
                    v-for="node in relatedRight"
                    :key="node.ulid"
                    :node="node"
                    :label="t('item.map.kind.related')"
                    :dot="DOTS.related"
                    @open-menu="openMenu"
                />

                <Link
                    v-if="moreRelated > 0"
                    :href="overflowHref"
                    class="flex min-h-11 items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
                >
                    {{ t('item.map.more', { count: moreRelated }) }}
                </Link>
            </div>
        </div>

        <div v-if="children.length > 0" aria-hidden="true" class="mx-auto h-4 w-px bg-border"></div>

        <ul v-if="children.length > 0" class="flex flex-wrap justify-center gap-2">
            <FocusMapNode
                v-for="node in children"
                :key="node.ulid"
                :node="node"
                :label="t('item.map.kind.child')"
                :dot="DOTS.child"
                @open-menu="openMenu"
            />
        </ul>

        <Link
            v-if="moreChildren > 0"
            :href="overflowHref"
            class="mx-auto mt-2 flex min-h-11 w-fit items-center rounded-control px-2 text-meta font-medium text-accent hover:underline"
        >
            {{ t('item.map.more', { count: moreChildren }) }}
        </Link>

        <!--
            Teckenförklaringen: de tre slagen, med samma ord och samma prickar
            som noderna bär. En karta vars färger inte förklaras är en karta
            man gissar på.
        -->
        <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
            <li
                v-for="kind in kinds"
                :key="kind"
                class="flex items-center gap-2 text-meta text-ink-muted"
            >
                <span aria-hidden="true" class="h-2.5 w-2.5 shrink-0 rounded-pill" :class="DOTS[kind]"></span>
                {{ t(`item.map.kind.${kind}`) }}
            </li>
        </ul>

        <!--
            Nodens meny. Arket ritas bara när någon nod har rader: en yta utan
            innehåll är inget mål, och servern har redan svarat att menyn är
            tom genom att inte skicka några rader.
        -->
        <CreateMenu
            v-if="menuRows.length > 0"
            :open="menuOpen"
            :trigger="menuTrigger"
            :rows="menuRows"
            :heading="menuName"
            @close="closeMenu"
        />
    </div>
</template>

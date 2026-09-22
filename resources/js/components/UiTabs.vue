<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiBadge from './UiBadge.vue';

/*
 * Flikraden, se issue 100 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **En komponent och inte två.** Containerns flikar och itemets är olika rader
 * med samma beteende, och byggs raden två gånger glider de isär inom samma
 * milstolpe — det har `DelningsvyTest` och `containerSections.js` redan visat
 * en gång var. Formen bor här; vilka flikar som finns vet bara anroparen.
 *
 * **Etiketten kommer som prop, aldrig ur en sträng i filen.** Fliktexten slås
 * upp ur `lang/` av anroparen, precis som `container.nav.<key>` gör i dag
 * ([[ADR-0021 Frontendteknik]]: webbens text formuleras på servern). Samma sak
 * med radens eget namn: `label` är tablistens tillgängliga namn och är
 * `required` med flit — en namnlös tablist är en skärmläsare som säger
 * "fliklista" och ingenting mer. SprakTest fångar den som skriver en literal
 * här i stället.
 *
 * **Räknaren är valfri och ritas bara när den finns.** `count` är `null` för
 * en flik utan tal, och då ritas ingen bricka alls: en nolla hade varit ett
 * påstående om innehållet som anroparen inte har gjort. Talet bärs av `UiBadge`
 * i dess neutrala tillstånd (issue 99) — flikens egen roll är aktiv eller inte,
 * och räknaren är varken ett OK eller ett fel.
 *
 * **Den aktiva fliken står i ADRESSEN, inte i komponentens tillstånd.** Samma
 * skäl som filtret i issue 59a § Beslut 1 och förekomsten i issue 95: en flik
 * man kan länka till är en flik man kan dela, och en som bara bor i minnet
 * försvinner vid en omladdning — eller, värre, står kvar och pekar fel när
 * webbläsaren går bakåt. `page.url` är därför enda källan, och komponenten
 * håller inget `ref` med valet i. Den som ändå lägger ett tillstånd här får
 * två sanningar så fort användaren klickar i webbläsarens historik.
 *
 * **Träffen är en regel med två villkor, och båda behövs:**
 *
 *   1. **Sökvägen.** Flikens sökväg är lika med adressens, eller ett prefix av
 *      den vid en segmentgräns: `/containers/{ulid}` matchar
 *      `/containers/{ulid}/items/{ulid}`. Översiktens sökväg är ett prefix
 *      till varandra fliks — därav villkor 2.
 *   2. **Flikens EGNA parametrar.** Varje queryparameter som står i flikens
 *      `href` ska ha samma värde i adressen. Parametrar som bara finns i
 *      adressen ignoreras.
 *
 * Itemets sex flikar ligger på samma sökväg och skiljs bara av sin querysträng
 * — samma konstruktion som `?path=` i issue 95 — så en träff på sökvägen hade
 * tänt alla sex. Och en jämförelse av HELA adressen hade släckt fliken man står
 * på så fort en främmande parameter kom in: `?tab=relations&sort=name` är
 * ingen fliks `href`, och `sort` är issue 59a:s filter, inte flikens.
 *
 * Bland de flikar som matchar vinner den med **flest matchade parametrar**,
 * sedan den med **längst sökväg**, sedan den **första i listan**. Ordningen är
 * hela poängen och inte en detalj: det är så `?tab=relations&sort=name` blir
 * entydig, och så översikten slutar lysa på varje undersida.
 *
 * Två följder för anroparen, och de är bindande:
 *
 *   - **Översiktsfliken skrivs utan querysträng.** Den är vilotillståndet, och
 *     det är villkor 2 som gör den entydig.
 *   - **Komponenten känner inte till parameterns namn.** Den jämför de
 *     parametrar anroparen råkar skriva i sin `href`; namnet väljs i issue 102
 *     och ska vara engelskt (AGENTS.md § Språk i koden) — `tab`, inte `flik`.
 *
 * **Tangentbordet är inte en efterhandsfråga** (issue 68a och 68b gick igenom
 * hela frontenden). Roving tabindex: bara den aktiva fliken är tabbbar
 * (`tabindex="0"`), de andra är `-1`, och därför LÄMNAR `Tab` raden i stället
 * för att vandra genom varje flik. Piltangenterna flyttar fokus inom raden,
 * och `Home`/`End` går till första och sista — WAI-ARIA:s mönster för en
 * tablist, och samma prov som resten av frontenden möttes av.
 *
 * Fokus flyttas men fliken AKTIVERAS inte av piltangenterna: en flik är en
 * adress, och automatisk aktivering hade skickat en förfrågan per nedtryckning
 * — en hållen piltangent hade hamrat servern. `Enter` följer länken, som för
 * varje annan länk. Det är WAI-ARIA:s "manual activation", och skälet är
 * konkret här och inte principiellt.
 *
 * `aria-controls` sätts inte: den pekar ut en panel i SAMMA dokument, och en
 * flik här är en egen sida. `aria-selected` sätts däremot på varje flik — även
 * `false` — för det är så en skärmläsare vet vilken som är vald.
 *
 * **Fokusringen är `--color-focus` och får aldrig tas bort**
 * ([[ADR-0042 Designsystemet]] § Beslut): `outline-none` utan en ring som tar
 * över lämnar fokus osynligt för den som tabbar, och river arbetet i issue 68a
 * och 68b. Här är den `focus-visible:`, som i knappen och de fyra
 * kontrollerna: en flik man klickar på behöver ingen ring, en man tabbar till
 * behöver den.
 *
 * Träffytan är `min-h-11` — 44 px ur issue 68a § Beslut 3 — och raden bryter
 * (`flex-wrap`) i stället för att skrolla i sidled på en telefon.
 */
const props = defineProps({
    /*
     * Flikarna i ritad ordning: `{ key, label, href, count }`. `key` är
     * Vue-nyckeln och ingenting annat, `label` är färdig text ur `lang/`,
     * `href` en adress, och `count` ett tal eller `null`.
     */
    tabs: { type: Array, required: true },
    /* Tablistens tillgängliga namn, ur `lang/` hos anroparen. */
    label: { type: String, required: true },
});

const page = usePage();

/*
 * En adress delad i sökväg och parametrar. `URLSearchParams` tolkar
 * procentkodning och `+` åt oss: `?tag=verk%20tyg` och `?tag=verk+tyg` är
 * samma värde, och en jämförelse på råtext hade sagt nej om det ena.
 */
function splitAddress(address) {
    const [path, query = ''] = address.split('?');

    return { path, params: new URLSearchParams(query) };
}

/*
 * Villkor 1 och 2 ovan, i den ordningen: sökvägen först, och sedan varje
 * parameter i flikens egen `href` mot adressen. Loopen går över FLIKENS
 * parametrar och slår upp dem i adressen — aldrig tvärtom, för det är så en
 * främmande parameter förblir ignorerad.
 */
function matches(tab, current) {
    if (current.path !== tab.path && !current.path.startsWith(`${tab.path}/`)) {
        return false;
    }

    for (const [name, value] of tab.params) {
        if (current.params.get(name) !== value) {
            return false;
        }
    }

    return true;
}

/*
 * Den aktiva fliken, läst ur adressen — se docblocken ovan för regeln och
 * rangordningen. `best` är kandidaten som leder; en senare flik tar över bara
 * när den är STRICKT bättre, och därför vinner den första i listan vid lika.
 */
const activeKey = computed(() => {
    const current = splitAddress(page.url);

    let best = null;

    for (const tab of props.tabs) {
        const address = splitAddress(tab.href);

        if (!matches(address, current)) {
            continue;
        }

        const matched = [...address.params].length;
        const better =
            best === null ||
            matched > best.matched ||
            (matched === best.matched && address.path.length > best.path.length);

        if (better) {
            best = { key: tab.key, matched, path: address.path };
        }
    }

    return best?.key ?? null;
});

const activeIndex = computed(() => props.tabs.findIndex((tab) => tab.key === activeKey.value));

const isActive = (tab) => tab.key === activeKey.value;

/*
 * Bara EN flik är tabbbar. Saknar adressen en träff — en sida under en flik
 * som ännu inte finns — är den första tabbbar i stället: annars hade hela
 * raden varit onåbar med tangentbord, och en navigation ingen når är samma
 * sak som ingen navigation.
 */
const isTabbable = (index) => index === (activeIndex.value === -1 ? 0 : activeIndex.value);

/*
 * DOM-noderna, inte valet. `tabElements` bär de renderade länkarna och
 * ingenting om vilken som gäller — den frågan besvaras av `activeKey` ovan.
 */
const tabElements = ref([]);

function setTabElement(element, index) {
    tabElements.value[index] = element;
}

function focusTab(index) {
    const element = tabElements.value[index];

    (element?.$el ?? element)?.focus();
}

/*
 * Piltangenterna flyttar fokus och snurrar vid ändarna: sista fliken följs av
 * den första, som i varje annan tablist. Allt annat — `Tab` inräknat — lämnas
 * till webbläsaren, och det är därför `Tab` lämnar raden.
 */
function onKeydown(event, index) {
    const last = props.tabs.length - 1;
    let target = null;

    if (event.key === 'ArrowRight') {
        target = index === last ? 0 : index + 1;
    } else if (event.key === 'ArrowLeft') {
        target = index === 0 ? last : index - 1;
    } else if (event.key === 'Home') {
        target = 0;
    } else if (event.key === 'End') {
        target = last;
    }

    if (target === null) {
        return;
    }

    event.preventDefault();
    focusTab(target);
}
</script>

<template>
    <ul
        role="tablist"
        :aria-label="label"
        class="flex flex-wrap gap-x-1 border-b border-border"
    >
        <li v-for="(tab, index) in tabs" :key="tab.key" role="presentation">
            <Link
                :ref="(element) => setTabElement(element, index)"
                :href="tab.href"
                role="tab"
                :aria-selected="isActive(tab) ? 'true' : 'false'"
                :tabindex="isTabbable(index) ? 0 : -1"
                class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="
                    isActive(tab)
                        ? 'border-accent font-medium text-accent'
                        : 'border-transparent text-ink-muted hover:bg-surface-sunken'
                "
                @keydown="onKeydown($event, index)"
            >
                {{ tab.label }}

                <UiBadge v-if="tab.count !== null && tab.count !== undefined">{{ tab.count }}</UiBadge>
            </Link>
        </li>
    </ul>
</template>

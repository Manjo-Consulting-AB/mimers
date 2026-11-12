<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerDetailsPanel from '../../components/ContainerDetailsPanel.vue';
import ContainerTasksPanel from '../../components/ContainerTasksPanel.vue';
import CostDonut from '../../components/CostDonut.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import InfoPanel from '../../components/InfoPanel.vue';
import OverviewPanelFrame from '../../components/OverviewPanelFrame.vue';
import UiButton from '../../components/UiButton.vue';
import ItemRow from '../../components/ItemRow.vue';
import RecentImagesPanel from '../../components/RecentImagesPanel.vue';
import UiCard from '../../components/UiCard.vue';
import UiEmptyState from '../../components/UiEmptyState.vue';
import UiIcon from '../../components/UiIcon.vue';
import UiStat from '../../components/UiStat.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { movePanelTo } from '../../support/overviewPanels.js';

defineOptions({
    layout: (props) => [
        [AppLayout, { create: props.create }],
        [ContainerLayout, { hero: 'large', container: props.container, can: props.can }],
    ],
});

/*
 * Containerns översikt — containerns egen sida, se issue 89 ·
 * [[ADR-0039 Containerns översikt]].
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource. Fram till issue 89 var itemlistan
 * den här sidan; nu ligger den på `/containers/{ulid}/items` och ritas av
 * pages/Containers/Items/Index.vue.
 *
 * **Huvudet är namn, art och beskrivning.** Arten skrivs ut ORDAGRANT — fältet
 * är fritt ([[ADR-0036 Containerns art]]), så ingen översättningsnyckel byggs
 * ur värdet: `t()` returnerar nyckeln själv när uppslaget misslyckas, och en
 * nyckel byggd ur strängen hade skrivit `container.overview.kind.Segelbåt` på
 * skärmen första gången någon skrev en egen art. Etiketten är `Kind` och inte
 * mockupens *Kategori*, som är upptaget av kategoriträdet på items — ett ord,
 * en betydelse ([[ADR-0032 Produktens ord]]).
 *
 * Beskrivningen ritas som den är skriven. Ingen formatering, ingen tolkning,
 * ingen uppdelning i delar: fältet är en fritext och inte en samling
 * strukturerade fakta (issue 88 · [[ADR-0039 Containerns översikt]] § Beslut).
 * Är den tom utelämnas raden — en tom etikett vore ett påstående om att något
 * saknas.
 *
 * **Brickorna räknar det användaren SJÄLV når** ([[ADR-0028 Åtkomst på
 * itemnivå]] § Konsekvenser, issue 73 § Beslut 6). Talen kommer färdigräknade
 * ur App\Http\Controllers\ContainerController::show(), och vyn räknar
 * ingenting: itembrickan är `ListItems` — samma Action som itemsidan och
 * itempanelen ritar ur — och är därför lika lång som listan per konstruktion,
 * medan uppgiftsbrickan är samma `ListTodo::forContainer()` som
 * uppgiftspanelen. Ingen totalsumma, ingen *av N*, ingen rad om att något
 * dolts: ett sådant tal är precis vad omfånget stänger ute.
 *
 * **Uppgifter och underhåll är EN bricka.** `schedule` har inget fält som
 * skiljer dem åt och får inte ett — skillnaden är domänen ([[ADR-0033
 * Produktens omfång]]), och mockupens två brickor är en teckning och inte ett
 * krav.
 *
 * Talen ritas av `UiStat` (issue 99), som är en form och ingen räknare — den
 * får `counts` rakt igenom och ställer ingen fråga själv.
 *
 * **Informationsytan kom med issue 128**, som sin egen propp: `tips` bär
 * samma lista som dashboarden bär, ur samma App\Support\Tips. Komponenten är
 * den samma — `InfoPanel.vue` ser identisk ut på båda ytorna, och det är
 * avsiktligt: [[ADR-0039 Containerns översikt]] § Konsekvenser säger att
 * informationsrutan är EN ruta, och en andra upplaga här hade kunnat visa
 * ett annat första tips. Texten kommer ur `tips.*` i `lang/`, alltså samma
 * nycklar som på dashboarden och inte `container.overview.*`: tipsen handlar
 * inte om containern man står i.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): varje
 * text kommer ur `t()` med en nyckel under `container.overview.*` eller
 * `tips.*`.
 *
 * **Hjälten kom med issue 170 · [[ADR-0050 Desktopdesignen]] § 2–3**, och
 * översikten är den sida som skickar `hero="large"`: bilden, arten, namnet och
 * hela beskrivningen står ovanpå containerns bild över `md:`. Sedan issue 680
 * läser hjälten sina egna brickor ur den delade proppen `containerCounts`; hit
 * hör bara vilken form sidan vill ha.
 *
 * **Sidans eget huvud ritas bara under `md:`.** Över brytpunkten bär hjälten
 * namnet, arten och beskrivningen, och samma regel som gäller namnraden i skalet
 * gäller här: två rader med samma text är en synlig dubblett och en skärmläsare
 * som läser fel. Varje rad i `<dl>` är ett fält hjälten upprepar; skulle ett
 * fält tillkomma som hjälten inte bär stannar det synligt på båda bredderna.
 * Under `md:` är huvudet oförändrat — där ritas ingen hjälte.
 *
 * **Talen ritas på två ställen.** Över `md:` bär hjälten dem ur den delade
 * proppen `containerCounts` (issue 680), och under `md:` — där hjälten inte
 * ritas alls — står de kvar i sidans flöde ur `counts` som förut. Att bara
 * behålla hjältens upplaga hade tyst tagit bort de två brickorna från en
 * telefon. Båda är samma siffror ur samma källa på servern (issue 679), och
 * ingen av raderna räknar något själv.
 *
 * **Panelerna kom med issue 172 · [[ADR-0050 Desktopdesignen]] § 7.** Sidan är
 * monteringspunkten och äger ingenting själv: varje panel har sin egen propp
 * och ritar vad servern gav den — vyn räknar ingenting, filtrerar ingenting
 * och formulerar ingen fråga.
 *
 * - *Kommande uppgifter* och *Items* är egna komponenter respektive egna
 *   rader, och båda länkar vidare: uppgifterna till `/tasks` till dess att
 *   containerns uppgiftsflik finns (issue 174), itemen till itemlistan.
 *   *Items* ritar `ItemRow` — samma rad som itemlistan (issue 285 ·
 *   [[M32 Formspråket]]) — i två kolumner över `lg:` och en under. Servern
 *   skickar `covers` för exakt de sex rader panelen visar.
 * - *Kostnader* ritas av `CostDonut` — samma komponent som dashboardens — och
 *   panelen ritas inte alls när containern saknar kostnadsrader: en rubrik över
 *   en tom ring är en yta som påstår att det finns något att visa.
 * - *Senaste aktiviteter* ritar `HistoryRow`, och den ber INTE om
 *   containerraden: raderna står inuti sin container här, till skillnad från
 *   dashboardens panel, och ett upprepat containernamn hade sagt samma sak två
 *   gånger (issue 116 § Beslut, issue 126).
 * - *Containerdetaljer* är sin egen komponent och en definitionslista.
 * - *Senaste bilder* kom med issue 173 och är sin egen komponent. Panelen
 *   ritas inte alls när containern saknar bilder — samma regel som
 *   kostnadspanelen, och av samma skäl: en rubrik över ingenting påstår att
 *   det finns något att visa.
 *
 * **Panelernas ordning och bredd är personens** ([[ADR-0073 Översiktens
 * ordning]] § 2–4). Servern skickar proppen `panels` — `{key, width}` i
 * personens ordning, ur App\Actions\User\ReadOverviewPanels — och sidan ritar
 * de fem panelerna i en loop över den. `wide` spänner två kolumner över
 * `lg:`, `narrow` ingen. Platsen räknas inte längre fram ur innehållet:
 * ingen `lg:order-*`, inga spann ur `costs.totals.length` eller
 * `recentImages.length`. Under `lg:` staplas panelerna i samma ordning.
 * Informationsytan står sist, utanför loopen.
 *
 * **Redigeringsläget kom med issue 1114 · ADR-0073 § 5.** `Customize
 * overview` öppnar det; då ritas en lokal kopia av `props.panels` och varje
 * panel får *Move up* och *Move down* (OverviewPanelFrame). En flytt ändrar
 * bara kopian. `Done` skickar den med `PUT /settings/overview-panels` och
 * `Reset to default` skickar `DELETE`; Inertia laddar om `panels`. Lämnas
 * sidan utan `Done` sparas ingenting. Efter en flytt flyttas fokus till samma
 * knapp i panelens nya läge — eller till den andra pilen, när den första
 * blev inaktiv i änden.
 *
 * Varje panel ritas alltid och visar sitt tomma läge; *Kostnader* har
 * `container.overview.costs_empty`. *Senaste bilder* inuti `items` ritas
 * fortfarande inte utan bilder — det ändrar bara panelens höjd.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär ur.
     * Här styr den pennan på bilden i skalets topprad (issue 159 ·
     * [[ADR-0047 Containerns bild]] § Beslut): den som bara får läsa ser
     * varken penna eller ark. Flaggan är presentation; rutten prövar
     * `ContainerPolicy::update` på nytt.
     */
    can: { type: Object, required: true },
    /* `{ items, todos }` — antalet items respektive öppna uppgifter inom omfånget. */
    counts: { type: Object, required: true },
    /* Högst fem rader ur containerns todo-urval, i serverns ordning (issue 172). */
    tasks: { type: Array, required: true },
    /* Containerns fasta summering: `{ totals, breakdown }` (issue 172). */
    costs: { type: Object, required: true },
    /* Högst sex items användaren når, ur samma ListItems-svar som `counts.items` (issue 172). */
    items: { type: Array, required: true },
    /*
     * Item-ULID → `{ulid, hasThumb}` eller null, ur
     * App\Actions\Item\ResolveItemCover: radens eget omslag (issue 285 ·
     * [[M32 Formspråket]]). Uppslaget gäller EXAKT de items panelen ritar —
     * de sex första ur samma `ListItems`-svar — så en gäst aldrig får ett
     * omslag för ett item hon inte når.
     */
    covers: { type: Object, required: true },
    /* Högst fem rader ur containerns händelselogg, nyast först (issue 172). */
    events: { type: Array, required: true },
    /*
     * Högst fem bilder på containerns items, nyast först (issue 173):
     * `{ulid, filename, hasThumb, item: {ulid, name}}`. Tom när containern
     * saknar bilder — och då ritas panelen inte alls.
     */
    recentImages: { type: Array, required: true },
    /* `{ kind, currency, account, created_at }` — containerns egna fakta (issue 172). */
    details: { type: Object, required: true },
    /*
     * Personens panelordning: `{key, width}` med `width` `narrow` eller
     * `wide`, ur ReadOverviewPanels (issue 350 · ADR-0073 § 2–3).
     */
    panels: { type: Array, required: true },
    /* Nycklarna på de tips användaren inte dolt, i serverns ordning. */
    tips: { type: Array, required: true },
    /*
     * Plusknappens mål, ur App\Support\Frontend\CreateTarget (issue 152 ·
     * [[ADR-0048 Mobilen och plusknappen]] § 2), eller null. Här skapar
     * knappen ett item i containern, med `?parent` när man står på en nod.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();

/* Panelens rubrik, för raden i redigeringsläget och för pilarnas etikett. */
const panelNames = computed(() => ({
    tasks: t('container.overview.tasks'),
    costs: t('container.overview.costs'),
    details: t('container.overview.details'),
    items: t('container.overview.items'),
    activity: t('container.overview.activity'),
}));

const editing = ref(false);
const draft = ref([]);
const saving = ref(false);
const shownPanels = computed(() => (editing.value ? draft.value : props.panels));

function startEditing() {
    draft.value = props.panels.map((panel) => ({ ...panel }));
    editing.value = true;
}

function movePanel(index, direction) {
    const target = direction === 'up' ? index - 1 : index + 1;

    if (target < 0 || target >= draft.value.length) {
        return;
    }

    const next = movePanelTo(draft.value, draft.value[index].key, draft.value[target].key);
    draft.value = next;

    const key = next[target].key;
    const atEnd = direction === 'up' ? target === 0 : target === next.length - 1;

    nextTick(() => {
        const wanted = atEnd ? (direction === 'up' ? 'down' : 'up') : direction;

        document.querySelector(`[data-move="${key}-${wanted}"]`)?.focus();
    });
}

/* Bredden (issue 1116): ett byte ändrar `width` i den lokala listan. */
function setWidth(key, width) {
    draft.value = draft.value.map((panel) => (panel.key === key ? { ...panel, width } : panel));
}

/* Draget (issue 1115): HTML5-drag på panelens ram, en genväg bredvid pilarna. */
const dragKey = ref(null);
const overKey = ref(null);

function dropPanel(targetKey, droppedKey) {
    draft.value = movePanelTo(draft.value, droppedKey, targetKey);
    dragKey.value = null;
    overKey.value = null;
}

function endDrag() {
    dragKey.value = null;
    overKey.value = null;
}

function finishEditing() {
    router.put(
        '/settings/overview-panels',
        { panels: draft.value },
        {
            onStart: () => { saving.value = true; },
            onFinish: () => { saving.value = false; },
            onSuccess: () => { editing.value = false; },
        },
    );
}

function resetEditing() {
    router.delete('/settings/overview-panels', {
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
        onSuccess: () => { editing.value = false; },
    });
}

const itemListUrl = computed(() => `/containers/${props.container.ulid}/items`);
const costsUrl = computed(() => `/containers/${props.container.ulid}/costs`);
const costSliceHref = (ulid) => `/containers/${props.container.ulid}/items/${ulid}`;
</script>

<template>
    <Head :title="container.name" />

    <!-- Knappraden ovanför panelerna (issue 1114). Utanför läget en
         sekundär knapp; i läget `Done` (primär) och `Reset to default`. -->
    <div class="mb-2 flex flex-wrap gap-2">
        <UiButton v-if="!editing" variant="secondary" @click="startEditing">
            {{ t('container.overview.customize.open') }}
        </UiButton>
        <template v-else>
            <UiButton variant="primary" :pending="saving" @click="finishEditing">
                {{ t('container.overview.customize.done') }}
            </UiButton>
            <UiButton variant="secondary" :pending="saving" @click="resetEditing">
                {{ t('container.overview.customize.reset') }}
            </UiButton>
        </template>
    </div>

    <!--
        Rutnätet (issue 172, omlagt i issue 1113 efter ADR-0073). Behållaren är
        ett vanligt block tills `lg:` gör den till ett rutnät om tre kolumner.
        Panelerna ritas i personens ordning och staplas i samma ordning under
        `lg:`; marginalen sitter på varje panel, så radrytmen är den samma på
        båda sidor om brytpunkten.
    -->
    <div class="lg:grid lg:grid-flow-dense lg:grid-cols-3 lg:items-start lg:gap-x-8">
        <!--
            Sidans eget huvud ritas bara under `md:`. Över brytpunkten är
            hjälten sidans huvud (ADR-0050 § 2), och den bär samma namn, art
            och beskrivning — två rader med samma text är både en synlig
            dubblett och en skärmläsare som läser fel. Namnet är hjältens
            `<h1>` där, så sidan har en rubrik på varje bredd.
        -->
        <h1 class="text-2xl font-semibold md:hidden">{{ container.name }}</h1>

        <!-- Varje rad i listan är ett fält hjälten upprepar — arten och
             beskrivningen — så hela listan hör till huvudet under `md:`. -->
        <dl class="mt-2 flex flex-col gap-1 text-slate-700 md:hidden">
            <div v-if="container.kind" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.kind') }}</dt>
                <dd>{{ container.kind }}</dd>
            </div>

            <div v-if="container.description" class="flex flex-wrap gap-x-2">
                <dt class="font-medium">{{ t('container.overview.description') }}</dt>
                <dd class="whitespace-pre-line">{{ container.description }}</dd>
            </div>
        </dl>

        <!-- Talen i sidans flöde under `md:`, där hjälten inte ritas. Samma
             två tal ur samma `counts` — ingen tredje bricka och ingen egen
             räkning. -->
        <div class="mt-8 flex flex-wrap gap-4 md:hidden">
            <UiStat :value="counts.items" :label="t('container.overview.items')" />
            <UiStat :value="counts.todos" :label="t('container.overview.todos')" />
        </div>

        <!-- Panelerna i personens ordning (ADR-0073 § 2). `wide` spänner två
             kolumner över `lg:`, `narrow` ingen. Varje panel ritas alltid och
             äger sitt tomma läge. -->
        <OverviewPanelFrame
            v-for="(panel, index) in shownPanels"
            :key="panel.key"
            :panel-key="panel.key"
            :name="panelNames[panel.key]"
            :editing="editing"
            :first="index === 0"
            :last="index === shownPanels.length - 1"
            :width="panel.width"
            class="mt-8"
            :class="panel.width === 'wide' ? 'lg:col-span-2' : ''"
            :drop-target="editing && overKey === panel.key && dragKey !== panel.key"
            @move="movePanel(index, $event)"
            @width="setWidth(panel.key, $event)"
            @panel-dragstart="dragKey = panel.key"
            @panel-dragover="overKey = panel.key"
            @panel-drop="dropPanel(panel.key, $event)"
            @panel-dragend="endDrag"
        >
            <ContainerTasksPanel v-if="panel.key === 'tasks'" :tasks="props.tasks" />

            <!-- Kostnaderna. Utan kostnadsrader visas det tomma läget i
                 stället för ringen (ADR-0073 § 4). Bildtexten i ringens mitt
                 säger *Total* och inte *This month*: den fasta summeringen är
                 hela containern och har ingen period (ADR-0038). -->
            <UiCard v-else-if="panel.key === 'costs'">
                <template #heading>
                    <UiIcon name="cost" />
                    {{ t('container.overview.costs') }}
                </template>

                <UiEmptyState v-if="props.costs.totals.length === 0">
                    {{ t('container.overview.costs_empty') }}
                </UiEmptyState>

                <CostDonut
                    v-else
                    :totals="props.costs.totals"
                    :breakdown="props.costs.breakdown"
                    :label="t('container.overview.costs_total')"
                    :slice-href="costSliceHref"
                    :href="costsUrl"
                />
            </UiCard>

            <ContainerDetailsPanel v-else-if="panel.key === 'details'" :details="props.details" />

            <!-- Items med Senaste bilder under (issue 321): bilderna staplas
                 under itemen med `mt-8`. Samma lista som itembrickan räknar,
                 klippt till sex av servern. -->
            <template v-else-if="panel.key === 'items'">
                <UiCard>
                    <template #heading>
                        <UiIcon name="item" />
                        {{ t('container.overview.items') }}
                    </template>

                    <template #action>
                        <Link
                            :href="itemListUrl"
                            class="inline-flex min-h-11 items-center text-accent hover:underline"
                        >
                            {{ t('container.overview.view_all') }}
                        </Link>
                    </template>

                    <UiEmptyState v-if="props.items.length === 0">
                        {{ t('item.index.empty') }}
                    </UiEmptyState>

                    <!--
                        Listan är en <ul> och raderna är <li> — formen `ItemRow`
                        ritar — så en skärmläsare hör hur många rader det finns
                        innan den läser den första. Två kolumner över `lg:`
                        enligt docs/Design/container.jpeg, en under.
                    -->
                    <ul v-else class="lg:grid lg:grid-cols-2 lg:gap-x-8">
                        <ItemRow
                            v-for="item in props.items"
                            :key="item.ulid"
                            :item="item"
                            :cover="props.covers[item.ulid]"
                            :container-ulid="props.container.ulid"
                        />
                    </ul>
                </UiCard>

                <!-- Senaste bilder (issue 173). Ritas inte alls utan bilder
                     (ADR-0073 § 4) och har inget tomt läge. -->
                <div v-if="props.recentImages.length" class="mt-8">
                    <RecentImagesPanel
                        :images="props.recentImages"
                        :container-ulid="props.container.ulid"
                    />
                </div>
            </template>

            <!-- Senaste aktiviteter. Raden är `HistoryRow` och ber INTE om
                 containerraden (issue 126). Det tomma läget är
                 historikflikens eget ord. -->
            <UiCard v-else-if="panel.key === 'activity'">
                <template #heading>
                    <UiIcon name="history" />
                    {{ t('container.overview.activity') }}
                </template>

                <UiEmptyState v-if="props.events.length === 0">
                    {{ t('audit.history.empty') }}
                </UiEmptyState>

                <ul v-else class="flex flex-col divide-y divide-slate-200">
                    <HistoryRow
                        v-for="event in props.events"
                        :key="event.ulid"
                        :row="event"
                    />
                </ul>
            </UiCard>
        </OverviewPanelFrame>

        <!-- Informationsytan står sist, efter loopen. Den äger sin egen
             marginal (se InfoPanel.vue) och ritas aldrig utan tips. -->
        <div>
            <InfoPanel :tips="props.tips" icon="tip" />
        </div>
    </div>
</template>

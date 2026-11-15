<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ContainerTaskQuickCreate from './ContainerTaskQuickCreate.vue';
import IncludeChildrenSwitch from './IncludeChildrenSwitch.vue';
import TaskGroup from './TaskGroup.vue';
import TodoRow from './TodoRow.vue';
import UiCard from './UiCard.vue';
import UiEmptyState from './UiEmptyState.vue';
import UiIcon from './UiIcon.vue';
import { useIncludeChildren } from '../composables/useIncludeChildren.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Itemets uppgiftsflik — listan av FÖREKOMSTER (M24 · issue 227, Tonys beslut
 * 2026-10-04, docs/Design/tasks-item.png).
 *
 * **Fliken visar samma grupper och samma rad som containerns flik** (Beslut 1
 * och 4): *Overdue → Today → This week → Upcoming → Done*, ur
 * App\Actions\Schedule\ListItemTasks, som frågar `ListTodo`s rader och grupper.
 * Gruppen räknas aldrig i vyn — en `computed` som jämför `due_at` mot
 * `Date.now()` hade flyttat en uppgift till fel hög så fort klientens klocka
 * gick fel (issue 64 § Beslut 3).
 *
 * **REGELLISTAN är borta.** Fram till issue 227 listade sektionen itemets
 * scheman med pausa, redigera och radera och en avbockning per rad. Reglerna
 * bor nu på schemats egen sida (issue 226), dit radens titel länkar, och
 * avbockningen är `TodoRow`s snabbavbockning (Beslut 5). Ingen `OpenOccurrence`
 * och ingen egen skrivväg här.
 *
 * **Rubrikkortet bär rubriken och underraden** (M28 · issue 785
 * § Beslut 1, docs/Design/tasks-item.png). Kortet är `UiCard`s rubrikrad och
 * åtgärdsplats: ikonrutan med bocken och rubriken *Tasks* till vänster,
 * och `item.schedule.subtitle` i kortets innehåll.
 * Mockupens sökfält och filter byggs inte (Beslut 3): uppgifterna har varken
 * tilldelning eller taggar, och fliken har inget filter i dag.
 *
 * **Reglaget *Include child items* är en växel och står i querysträngen**
 * (Beslut 2 och 3). Växeln postar ingen kropp: `router.get` mot SAMMA rutt som
 * sidan ligger på, med `children=0` när den är av och utan parametern när den
 * är på — så ett avgränsat läge är en adress man kan spara, dela och backa ur
 * (issue 59a § Beslut 1). Servern äger svaret, och `props.includeChildren`
 * speglas i växeln så att den följer servern efter en bakåtknapp eller en
 * omladdning. Vänteläget stänger kontrollen medan svaret är på väg (issue 68a
 * § Beslut 4).
 *
 * **Knappen och navigeringen bor i två delade filer** (issue 1247 ·
 * [[ADR-0068 Itemets sida och skapandet]] § Uppföljning 2026-11-14 punkt 1):
 * `IncludeChildrenSwitch.vue` ritar knappen och hjälptexten, och
 * `useIncludeChildren.js` äger läget, vänteläget och `router.get`-anropet.
 * *Documents* — och sedan *Costs* och *Loans* — använder samma två, så
 * flikarna inte kan svara olika på samma val. Formen är densamma som förut:
 * `role="switch"` med `aria-checked`, och etiketten ligger i knappen och blir
 * därför dess namn.
 *
 * **En tom grupp ritas inte** (Beslut 4) — varken rubrik eller lista, och det
 * gäller också *Done*. Är allt tomt ritas `item.schedule.empty` i stället: en
 * rubrik utan rader hade sagt att gruppen finns men är tom, och en lista utan
 * innehåll är samma svar utan brus.
 *
 * **`can` är presentation** (Beslut 4). Bara `can.create` läses här — snabbraden
 * — och rutten prövar `create` på nytt. Pausen, redigeringen och
 * raderingen ritas på schemats sida, bakom sina egna flaggor.
 *
 * Ordningen i *Done* kommer färdig från servern, och raden är `TodoRow` som de
 * öppna (M24 · issue 230, Beslut 5): komponenten ser `completed_at` och skriver
 * tidsstämpeln med `eventDate()` som en HÄNDELSE och inte som ett
 * förfallodatum (issue 104) — en avbockning är något som gjordes, inte något
 * som förfaller. Ingen sträng står i JavaScript (issue 52 · [[ADR-0013 Språk
 * och i18n]]): rubriken, knappen, reglaget och grupprubrikerna kommer ur `t()`.
 *
 * **Rubriken med antalet och ihopfällningen ritas av `TaskGroup`** (M24 ·
 * issue 231): sektionen skickar in gruppens namn, antalet och tonen, och
 * komponenten ritar knappen, chevronen och raderna — samma komponent som
 * containerns flik och `/tasks`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /*
     * De fyra öppna grupperna, i ritningsordning: overdue, today, this_week,
     * upcoming. Varje rad är `ListTodo`s rad — `TodoEntryResource` plus
     * `account`, `can`, `paused` och `blocked` — och vyn ritar den som den kom.
     */
    groups: { type: Object, required: true },
    /* Avbockade förekomster på itemet och ättlingarna, nyast först, högst tjugo. */
    completed: { type: Array, required: true },
    /* Reglagets läge, ur `?children`. Förvalet är PÅ. */
    includeChildren: { type: Boolean, required: true },
    /* `{ create }` — snabbraden. Rutten prövar samma grind. */
    /*
     * Sant när adressen bär `create=1` (issue 1212 · [[ADR-0074 Registrering i
     * vyn]], tillägget om itemets flikar): plusmenyns och snabbknappens rad
     * landar här med fokus i snabbraden.
     */
    startOpen: { type: Boolean, default: false },
    can: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Snabbraden och dess fokus: `startOpen` ger raden fokus (issue 1212). Ett val
 * i plusmenyn är en `<Link>` utan `preserve-state`, alltså ett vanligt
 * GET-besök: `@inertiajs/vue3` ger sidkomponenten en ny nyckel och monterar om
 * `Show.vue` och den här sektionen, och det är `onMounted` som ger fokus.
 * Bevakningen står kvar för ett besök som BEVARAR tillståndet — då monteras
 * inget om, och värdet slår om i stället.
 */
const quick = ref(null);

async function focusQuick() {
    await nextTick();
    quick.value?.focus();
}

onMounted(() => {
    if (props.startOpen && props.can.create) {
        focusQuick();
    }
});

watch(
    () => props.startOpen,
    (start) => {
        if (start && props.can.create) {
            focusQuick();
        }
    },
);

/*
 * Växeln: läget, vänteläget och navigeringen ur `useIncludeChildren`, så att
 * *Documents* och de andra flikarna gör exakt samma sak (issue 1247). Adressen
 * är flikens egen — `router.get` lägger parametern ovanpå querysträngen, så
 * `tab=schedules` står kvar och `children=0` läggs till eller utelämnas.
 */
const { onlyChildren, pending, toggle: toggleChildren } = useIncludeChildren(
    () => props.includeChildren,
    () => `/containers/${props.containerUlid}/items/${props.itemUlid}?tab=schedules`,
);

/*
 * Är allt tomt? Frågan ställs på serverns svar och inte på en egen räkning:
 * grupperna är redan avgränsade av reglaget, och vyn lägger inget villkor till
 * dem (Beslut 4).
 */
const hasAnyTask = computed(
    () => props.completed.length > 0 || Object.values(props.groups).some((entries) => entries.length > 0),
);

/*
 * Snabbraden (M58 · issue 1210, ADR-0074 tillägg 2026-11-13). *New task*-
 * knappen och länken till `/tasks/create` är borta: uppgiften skapas i vyn,
 * utan återkommande, och den som vill ha ett schema öppnar uppgiften efteråt.
 */
const page = usePage();

/*
 * Itemets namn till växelns hjälptext. Det läses ur `page.props.item` i
 * stället för att skickas in, samma väg som `containerCounts` i ContainerHero
 * och `costSuppliers` i ItemCostSection: sektionen får sitt item som ULID, och
 * namnet är sidans — den som ritar fliken har det redan.
 */
const itemName = computed(() => page.props.item?.name ?? '');

/*
 * Snabbradens item, och ULID:erna i sektionens grupper. Snabbraden jämför dem
 * före och efter ett svar och skickar de nya i `created`, som markeras med
 * `bg-accent-soft` i 2000 ms, som i Containers/Tasks.vue. *Done* räknas inte.
 */
const quickItem = computed(() => ({ ulid: props.itemUlid, name: itemName.value }));

const entryUlids = computed(() => Object.values(props.groups).flat().map((entry) => entry.ulid));

const highlighted = ref(new Set());

let highlightTimer = null;

function onCreated(ulids) {
    highlighted.value = new Set(ulids);

    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => {
        highlighted.value = new Set();
    }, 2000);
}
</script>

<template>
    <section class="mt-10">
        <!--
            Rubrikkortet (Beslut 1). Ikonrutan med bocken och rubriken till
            vänster och underraden i kortets
            innehåll. Sökfältet och filtren i mockupen byggs inte (Beslut 3).
        -->
        <UiCard>
            <template #heading>
                <span class="flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-control bg-accent-soft text-accent"
                    >
                        <UiIcon name="check" :size="20" class="size-5" />
                    </span>

                    {{ t('item.schedule.heading') }}
                </span>
            </template>

            <p class="text-body text-ink-muted">{{ t('item.schedule.subtitle') }}</p>
        </UiCard>

        <!--
            Reglaget (Beslut 2 och 3). Knappen och hjälptexten ritas av
            IncludeChildrenSwitch (issue 1247), och läget, vänteläget och
            navigeringen kommer ur useIncludeChildren — samma två filer som
            *Documents* använder, så flikarna inte kan glida isär.
        -->
        <IncludeChildrenSwitch
            :model-value="onlyChildren"
            :pending="pending"
            :help="t('item.schedule.children_help', { item: itemName })"
            @update:model-value="toggleChildren"
        />

        <!-- Snabbraden: rad noll, före det tomma läget och grupperna
             (ADR-0074 § 2). Utan platsknapp, platsen är itemet. -->
        <ContainerTaskQuickCreate
            v-if="can.create"
            ref="quick"
            class="mt-4"
            :container-ulid="containerUlid"
            :item="quickItem"
            :entry-ulids="entryUlids"
            @created="onCreated"
        />

        <!--
            Det tomma läget (Beslut 4): först när allt är tomt — de fyra öppna
            grupperna OCH *Done* — annars hade en lista med bara avbockade
            rader sagt att itemet saknar uppgifter.
        -->
        <UiEmptyState v-if="!hasAnyTask" class="mt-4">
            {{ t('item.schedule.empty') }}
        </UiEmptyState>

        <!--
            Listan (Beslut 4): grupperna i den ordning servern gav dem, sedan
            *Done*. En sektion ritas bara när den har rader, och rubriken byggs
            ur gruppens EGET namn — samma nyckel som containerns flik. Rubriken
            med antalet och ihopfällningen ritas av TaskGroup (M24 · issue
            231), samma komponent som de två andra ytorna.
        -->
        <div v-else class="mt-6 flex flex-col gap-8">
            <template v-for="(entries, group) in groups" :key="group">
                <TaskGroup
                    v-if="entries.length > 0"
                    :heading="t(`todo.group.${group}`)"
                    :count="entries.length"
                    :tone="group === 'overdue' ? 'danger' : null"
                >
                    <TodoRow
                        v-for="entry in entries"
                        :key="entry.ulid"
                        :entry="entry"
                        :show-container="false"
                        :class="{ 'bg-accent-soft': highlighted.has(entry.ulid) }"
                    />
                </TaskGroup>
            </template>

            <!--
                *Done* (Beslut 4). Raden bär samma upplysningar som de öppna —
                schemats titel, itemet, ett datum — men datumet är
                `completed_at`, och avbockningsknappen ritas inte: det finns
                ingenting kvar att bocka av. Rubriken är containerns fliks ord,
                samma grupp och samma nyckel.
            -->
            <TaskGroup
                v-if="completed.length > 0"
                :heading="t('container.tasks.done')"
                :count="completed.length"
            >
                <TodoRow
                    v-for="entry in completed"
                    :key="entry.ulid"
                    :entry="entry"
                    :show-container="false"
                />
            </TaskGroup>
        </div>
    </section>
</template>

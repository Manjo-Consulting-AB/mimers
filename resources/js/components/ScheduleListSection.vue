<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import TaskGroup from './TaskGroup.vue';
import TodoRow from './TodoRow.vue';
import UiCard from './UiCard.vue';
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
 * **Rubrikkortet bär rubriken, underraden och knappen** (M28 · issue 785
 * § Beslut 1, docs/Design/tasks-item.png). Kortet är `UiCard`s rubrikrad och
 * åtgärdsplats: ikonrutan med bocken och rubriken *Tasks* till vänster,
 * *New task* uppe till höger, och `item.schedule.subtitle` i kortets innehåll.
 * Mockupens sökfält och filter byggs inte (Beslut 3): uppgifterna har varken
 * tilldelning eller taggar, och fliken har inget filter i dag.
 *
 * **Reglaget *Include child items* är en växel och står i querysträngen**
 * (Beslut 2 och 3). Växeln postar ingen kropp: `router.get` mot SAMMA rutt som
 * sidan ligger på, med `children=0` när den är av och utan parametern när den
 * är på — så ett avgränsat läge är en adress man kan spara, dela och backa ur
 * (issue 59a § Beslut 1). Servern äger svaret, och `props.includeChildren`
 * speglas med `watch` så att växeln följer servern efter en bakåtknapp eller en
 * omladdning. Vänteläget stänger kontrollen medan svaret är på väg (issue 68a
 * § Beslut 4). Samma konstruktion som underhållsfiltret i
 * resources/js/pages/Containers/Tasks.vue, och samma form som
 * `UpcomingTasksToggle`: `role="switch"` med `aria-checked`, och etiketten
 * ligger i knappen och blir därför dess namn.
 *
 * **En tom grupp ritas inte** (Beslut 4) — varken rubrik eller lista, och det
 * gäller också *Done*. Är allt tomt ritas `item.schedule.empty` i stället: en
 * rubrik utan rader hade sagt att gruppen finns men är tom, och en lista utan
 * innehåll är samma svar utan brus.
 *
 * **`can` är presentation** (Beslut 4). Bara `can.create` läses här — knappen
 * *New task* — och rutten prövar `create` på nytt. Pausen, redigeringen och
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
    /* `{ create }` — *New task*-länken. Rutten prövar samma grind. */
    can: { type: Object, required: true },
});

const { t } = useTranslations();

/* Växelns läge, speglat ur proppen — se docblocken ovan. */
const onlyChildren = ref(props.includeChildren);

/* Vänteläget för reglaget: en enda kontroll, en enda flagga (issue 68a). */
const pending = ref(false);

watch(
    () => props.includeChildren,
    (value) => {
        onlyChildren.value = value;
    },
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
 * Flikens egen adress. `router.get` lägger parametern ovanpå querysträngen, så
 * `tab=schedules` står kvar och `children=0` läggs till eller utelämnas.
 */
const tabUrl = () => `/containers/${props.containerUlid}/items/${props.itemUlid}?tab=schedules`;

/*
 * Växlingen vrider läget och skickar det — två steg, som kryssrutan före den
 * gjorde med `v-model` och `@change`. Knappen är avstängd medan svaret är på
 * väg, så ett dubbelklick kan inte vrida läget två gånger.
 */
function toggleChildren() {
    onlyChildren.value = ! onlyChildren.value;

    apply();
}

function apply() {
    const params = onlyChildren.value ? {} : { children: 0 };

    router.get(tabUrl(), params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * *New task* (M27 · issue 246 § Beslut 2). Fliken är en av de tre ytorna som
 * bär knappen, och den här är den enda som förväljer ett item: knappen bär
 * sektionens item i adressen, så formuläret öppnas med platsen redan satt.
 *
 * Adressen bär `return` med den här sidan (Beslut 3), så svaret landar på
 * itemet man stod på — `page.url` är adressen med sin querysträng, och fliken
 * och reglaget man står i följer därför med tillbaka.
 *
 * **Det är en `<Link>` och inte en `<UiButton>`.** Knappen navigerar, och
 * GenomgangTest tillåter bara `<Link>` bland komponenttaggarna: en `@click` på
 * en komponent är en klickyta provet inte kan se är tabbbar. Klasserna är
 * `UiButton`s `primary`, så formen är densamma som i designsystemet.
 */
const page = usePage();

const createUrl = computed(
    () => `/tasks/create?item=${props.itemUlid}&return=${encodeURIComponent(page.url)}`,
);

/*
 * Itemets namn till växelns hjälptext. Det läses ur `page.props.item` i
 * stället för att skickas in, samma väg som `containerCounts` i ContainerHero
 * och `costSuppliers` i ItemCostSection: sektionen får sitt item som ULID, och
 * namnet är sidans — den som ritar fliken har det redan.
 */
const itemName = computed(() => page.props.item?.name ?? '');
</script>

<template>
    <section class="mt-10">
        <!--
            Rubrikkortet (Beslut 1). Ikonrutan med bocken och rubriken till
            vänster, *New task* uppe till höger, och underraden i kortets
            innehåll. Sökfältet och filtren i mockupen byggs inte (Beslut 3).
        -->
        <UiCard>
            <template #heading>
                <span class="flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-control bg-accent-soft text-accent"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-5"
                        >
                            <path d="M20 6 9 17l-5-5"></path>
                        </svg>
                    </span>

                    {{ t('item.schedule.heading') }}
                </span>
            </template>

            <!-- *New task* (M27 · issue 246 § Beslut 2): den gamla textlänken
                 är en knapp, och den förväljer sektionens item. Grinden är
                 `can.create` — rutten prövar samma pinne på nytt. -->
            <template #action>
                <Link
                    v-if="can.create"
                    :href="createUrl"
                    class="inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-body font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="mr-2 h-4 w-4"
                        aria-hidden="true"
                    >
                        <path d="M12 5v14"></path>
                        <path d="M5 12h14"></path>
                    </svg>

                    {{ t('todo.new') }}
                </Link>
            </template>

            <p class="text-body text-ink-muted">{{ t('item.schedule.subtitle') }}</p>
        </UiCard>

        <!--
            Reglaget (Beslut 2 och 3). En växel och ingen kryssruta: läget är
            ett värde i adressen, och svaret ritar servern. Etiketten ligger i
            knappen, `aria-checked` säger läget, och `:disabled` stänger
            kontrollen medan svaret är på väg (issue 68a § Beslut 4).
        -->
        <button
            type="button"
            role="switch"
            :aria-checked="onlyChildren ? 'true' : 'false'"
            :disabled="pending"
            class="mt-4 inline-flex min-h-11 w-fit items-center gap-2 text-meta font-medium text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
            @click="toggleChildren"
        >
            <span
                aria-hidden="true"
                class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
                :class="onlyChildren ? 'bg-accent' : 'bg-border'"
            >
                <span
                    class="inline-block size-3.5 rounded-full bg-surface transition-transform"
                    :class="onlyChildren ? 'translate-x-[1.25rem]' : 'translate-x-1'"
                />
            </span>

            <span>{{ t('item.schedule.include_children') }}</span>
        </button>

        <!-- Hjälptexten står under etiketten och i linje med den: spåret är
             36 px och gapet 8, alltså `pl-11`. -->
        <p class="mt-1 pl-11 text-meta text-ink-muted">
            {{ t('item.schedule.children_help', { item: itemName }) }}
        </p>

        <!--
            Det tomma läget (Beslut 4): först när allt är tomt — de fyra öppna
            grupperna OCH *Done* — annars hade en lista med bara avbockade
            rader sagt att itemet saknar uppgifter.
        -->
        <p v-if="!hasAnyTask" class="mt-4 text-sm text-slate-600">
            {{ t('item.schedule.empty') }}
        </p>

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

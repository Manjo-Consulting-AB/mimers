<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ItemTargetPicker from '../../components/ItemTargetPicker.vue';
import ScheduleForm from '../../components/ScheduleForm.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Skapa en uppgift — M27 · issue 246, se [[ADR-0054 Inboxen]] § 5 och § 8 och
 * förlagan docs/Design/tasks-container.png.
 *
 * **Den tredje vägen in i ett schema, och den enda utan ett item.** Itemets
 * egen sida (Containers/Items/Schedules/Create.vue, issue 63a) kräver ett item
 * och `/inbox` (issue 245) fångar bara en titel. Härifrån skapas uppgiften
 * antingen på ett item — den här sidans egen väg — eller i inboxen.
 *
 * **Platsen är sidans egen uppfinning, och formuläret vet ingenting om den.**
 * ScheduleForm får bara en annan POST-adress — `createUrl` är `/inbox/tasks`
 * när platsen är inboxen och itemets egen rutt annars — och `showList`, som är
 * false för inboxen: en uppgift i inboxen är obearbetad och har ingen lista
 * (ADR-0054 § 5), så *List*-fältet varken ritas eller skickas. Ingen ny
 * skrivregel tillkommer — de två rutterna finns sedan issue 63a och 245.
 *
 * **`item` ur `?item=` är förvalet, och `ItemTargetPicker` byter det.** Utan
 * item är platsen inboxen: en container är inte ett item (Beslut 2), och på
 * `/tasks` finns ingen plats alls. *Inbox* står som ett eget val bredvid
 * *Change…*, så vägen tillbaka till inboxen finns kvar efter ett byte.
 *
 * **Målväljaren lämnar itemet utan dess container** (issue 242): raden bär
 * `{ulid, name}` och gruppen runt den bär containern. Platsfältet visar båda,
 * och POST-adressen byggs ur containerns ULID — därför slås gruppen upp i
 * `page.props.itemTargets` när valet kommer tillbaka. Listan är den väljaren
 * själv hämtade, så uppslaget kostar ingen förfrågan.
 *
 * **Formuläret monteras om när platsen byts** (`:key`). Ett halvfyllt formulär
 * hör till den plats det fylldes i: byter man från ett item till inboxen ska
 * fälten börja om, inte följa med till en annan mottagare.
 *
 * `returnUrl` är den relativa adressen användaren kom från — servern har redan
 * nekat allt som inte börjar med `/` — och den följer med POST:en så svaret
 * landar där hon stod (Beslut 3). Utan den är `/tasks` standard.
 *
 * Ingen sträng i filen (issue 52 · [[ADR-0013 Språk och i18n]]): varje ord
 * kommer ur `lang/en/ui.php` genom `t()`.
 */
const props = defineProps({
    /* Itemet ur `?item=`, med sin container, eller null när platsen är inboxen. */
    item: { type: Object, default: null },
    /* Den relativa adressen att gå tillbaka till, eller null för `/tasks`. */
    returnUrl: { type: String, default: null },
});

const { t } = useTranslations();

const page = usePage();

/* Platsen: itemet, eller null för inboxen. */
const place = ref(props.item);

const pickerOpen = ref(false);
const pickerTrigger = ref(null);

/*
 * POST-adressen (Beslut 1). Itemets rutt är den befintliga
 * `containers.items.schedules.store`; inboxens är `POST /inbox/tasks`.
 */
const createUrl = computed(() => (place.value === null
    ? '/inbox/tasks'
    : `/containers/${place.value.container.ulid}/items/${place.value.ulid}/schedules`));

/* Nyckeln: ett nytt formulär när platsen byts — se docblocken ovan. */
const formKey = computed(() => place.value?.ulid ?? 'inbox');

/* Det valda itemet med sin container. Ritas bara när platsen är ett item —
 * är den inboxen bär knappen själv ordet. */
const placeName = computed(() => (place.value === null
    ? ''
    : `${place.value.container.name} · ${place.value.name}`));

function chooseInbox() {
    place.value = null;
}

function openPicker(event) {
    pickerTrigger.value = event.currentTarget;
    pickerOpen.value = true;
}

function onTargetChosen(item) {
    const group = (page.props.itemTargets ?? [])
        .find((entry) => entry.items.some((candidate) => candidate.ulid === item.ulid));

    place.value = { ...item, container: group.container };
    pickerOpen.value = false;
}
</script>

<template>
    <Head :title="t('todo.create.title')" />

    <h1 class="text-2xl font-semibold">{{ t('todo.new') }}</h1>

    <div class="mt-8 max-w-lg">
        <!--
            Platsen (Beslut 1). *Inbox* är ett eget val och inte bara
            frånvaron av ett item: efter ett byte till ett item ska vägen
            tillbaka till inboxen finnas kvar, och den står därför bredvid
            *Change…* på samma rad.

            Två råa `<button>` och inte `UiButton`: båda bär en `@click`,
            och GenomgangTest tillåter bara webbläsarens egna element som
            klickbar yta (samma skäl som ItemTargetPicker anger för sin
            bekräftelseknapp). Klasserna är `UiButton`s, så formen är
            designsystemets.
        -->
        <p class="text-sm font-medium text-slate-700">{{ t('todo.create.place') }}</p>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            <button
                type="button"
                :aria-pressed="place === null"
                class="inline-flex min-h-11 items-center justify-center rounded-control border px-3 text-meta font-medium outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="place === null
                    ? 'border-accent bg-accent text-ink-on-accent'
                    : 'border-border bg-surface text-ink hover:bg-surface-sunken'"
                @click="chooseInbox"
            >
                {{ t('todo.create.inbox') }}
            </button>

            <span v-if="place !== null" class="text-body text-ink">{{ placeName }}</span>

            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="openPicker"
            >
                {{ t('todo.create.change') }}
            </button>
        </div>

        <ScheduleForm
            :key="formKey"
            class="mt-8"
            :container-ulid="place?.container.ulid ?? ''"
            :item-ulid="place?.ulid ?? ''"
            :create-url="createUrl"
            :return-url="returnUrl ?? '/tasks'"
            :show-list="place !== null"
        />
    </div>

    <!-- Samma väljare som flytten och bearbetningen (issue 242). -->
    <ItemTargetPicker
        :open="pickerOpen"
        :trigger="pickerTrigger"
        :heading="t('todo.create.place')"
        @choose="onTargetChosen"
        @close="pickerOpen = false"
    />
</template>

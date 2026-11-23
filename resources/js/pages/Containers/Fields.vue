<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue';
import FieldRow from '../../components/fields/FieldRow.vue';
import FieldTypeSelect from '../../components/fields/FieldTypeSelect.vue';
import FormField from '../../components/FormField.vue';
import HelpLink from '../../components/HelpLink.vue';
import UiButton from '../../components/UiButton.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
        [ContainerSettingsLayout, { container: props.container }],
    ],
});

/*
 * Containerns egna fält, se issue 419 och [[ADR-0089 Egna fält på items]]
 * § Riktning 1–4.
 *
 * Sidan ligger i ContainerSettingsLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource. Den nås ur
 * sektionskolumnen (`containerSections.js`, nyckeln `fields`) och svarar på
 * GET /containers/{container}/fields.
 *
 * **Listan är fälten i `position`-ordning**, samma rader som `/api` svarar
 * med (App\Http\Resources\ItemFieldResource). Rubriken och typen ändras av
 * raden själv (`FieldRow.vue`), och ordningen av DEN HÄR sidan: sorteringen
 * gäller listan och inte en rad, och raden vet inte vilka grannar den har.
 *
 * **Ordningen byts genom att dra, och med två knappar.** Draget är
 * webbläsarens HTML5-drag (samma grep som `OverviewPanelFrame.vue`, inget
 * nytt beroende), och upp- och nedknapparna är tangentbordets väg till samma
 * skrivning. Båda skickar samma PATCH — `/fields/order` med ULID:erna i
 * önskad ordning — och svaret ritar om listan ur serverns svar. Den lokala
 * ordningen skrivs först, så raden flyttar sig direkt och inte efter
 * rundturen.
 *
 * **Antalet står mot gränsen** (`:used of :limit fields`), ur `limit`-propen
 * som är `fields.max_per_container`. Bara fält som lever räknas — fält i
 * papperskorgen syns inte och upptar ingen plats.
 *
 * **Ett fält med värden har ett låst typval** (§ Riktning 3). `values` är
 * antalet värden per fält, ULID → tal, ur kontrollern, och raden får veta om
 * talet är större än noll. Servern prövar låset själv
 * (`field.type_locked`); flaggan förklarar bara varför väljaren är grå.
 *
 * **Bara den med skrivrätt ser redigeringen** (§ Riktning 3): `can.manage` är
 * `ContainerPolicy::update` på containern, räknad av kontrollern. Flaggan är
 * presentation — varje skrivning auktoriserar på nytt i kontrollern — men en
 * `read`-mottagare ser listan, rubrikerna och ordningen, vilket är hela
 * poängen med att sidan grindas på `view`.
 *
 * **Fel på `field` har ingen radidentitet.** Kontrollern lägger gränsen,
 * rubrikkrocken och typens lås på formulärnyckeln `field` (issue 417), och
 * felpåsen kan inte säga vilken rad som nekades. Sidan minns därför vilken
 * rad — eller det nya fältet — som skickades senast och ritar meningen bara
 * där, samma mönster som Categories.vue gör för raderingar.
 */
const props = defineProps({
    container: { type: Object, required: true },
    fields: { type: Array, required: true },
    /* ULID → antalet värden fältet har, ur ItemFieldController::index(). */
    values: { type: Object, required: true },
    /* Taket för containerns fält, ur `fields.max_per_container`. */
    limit: { type: Number, required: true },
    /* Typerna, ur ItemField::TYPES. */
    types: { type: Array, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

/* ULID → fält, så ordningen kan ritas ur en lista av ULID:er. */
const byUlid = computed(() => Object.fromEntries(props.fields.map((field) => [field.ulid, field])));

/*
 * Ordningen som ritas. Den skrivs av ett drag eller en knapp och läses sedan
 * ur serverns svar — en gemensam källa, så att en misslyckad sortering faller
 * tillbaka på det som faktiskt står i databasen.
 */
const order = ref(props.fields.map((field) => field.ulid));

watch(
    () => props.fields,
    (fields) => {
        order.value = fields.map((field) => field.ulid);
    },
    { deep: true },
);

const ordered = computed(() => order.value.map((ulid) => byUlid.value[ulid]).filter(Boolean));

/* Raden som senast skickades — bara den ritar det generella felet. */
const errorTarget = ref(null);

const fieldError = computed(() => page.props.errors.field ?? null);

const visibleError = (ulid) => (errorTarget.value === ulid ? fieldError.value : null);

const dragging = ref(null);

function dragStart(ulid) {
    dragging.value = ulid;
}

function drop(overUlid) {
    const ulid = dragging.value;

    dragging.value = null;

    if (ulid === null || ulid === overUlid) {
        return;
    }

    moveTo(ulid, overUlid);
}

/* Flyttar $ulid till platsen $target har, och skickar den nya ordningen. */
function moveTo(ulid, target) {
    const next = order.value.filter((candidate) => candidate !== ulid);
    const index = next.indexOf(target);

    if (index === -1) {
        return;
    }

    next.splice(index, 0, ulid);
    sendOrder(next);
}

/* Upp- och nedknapparna: ett steg i listan, samma PATCH som draget. */
function step(ulid, direction) {
    const from = order.value.indexOf(ulid);
    const to = from + direction;

    if (from === -1 || to < 0 || to >= order.value.length) {
        return;
    }

    const next = [...order.value];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    sendOrder(next);
}

const reordering = ref(false);

function sendOrder(ulids) {
    order.value = ulids;
    reordering.value = true;
    errorTarget.value = 'order';

    router.patch(`/containers/${props.container.ulid}/fields/order`, { fields: ulids }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            reordering.value = true;
        },
        onFinish: () => {
            reordering.value = false;
        },
    });
}

/*
 * Det nya fältet. `position` skickas inte: App\Actions\Field\CreateField
 * sätter nästa lediga, och en klient som räknade själv hade gissat fel när
 * två flikar är öppna.
 */
const createForm = useForm({
    label: '',
    type: props.types[0] ?? 'text',
});

const atLimit = computed(() => props.fields.length >= props.limit);

function create() {
    errorTarget.value = 'new';

    createForm.post(`/containers/${props.container.ulid}/fields`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            createForm.reset('label');
        },
    });
}

const atLimitText = computed(() => t('container.fields.limit_reached', { limit: props.limit }));
</script>

<template>
    <Head :title="t('container.fields.title')" />

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h1 class="text-2xl font-semibold">{{ t('container.fields.heading') }}</h1>

        <span class="flex items-center">
            <HelpLink context="container.fields" icon-only class="sm:hidden" />
            <HelpLink context="container.fields" class="max-sm:hidden" />
        </span>
    </div>
    <p class="mt-1 text-sm text-ink-muted">{{ t('container.fields.description') }}</p>

    <!-- Antalet mot gränsen. Det står för alla som når sidan — en läsare ska
         se hur fullt containern är även om hon inte får fylla på den. -->
    <p class="mt-2 text-sm text-ink-subtle">
        {{ t('container.fields.count', { used: fields.length, limit }) }}
    </p>

    <ul v-if="ordered.length > 0" class="mt-6 flex flex-col gap-3">
        <FieldRow
            v-for="field in ordered"
            :key="field.ulid"
            :container-ulid="container.ulid"
            :field="field"
            :types="types"
            :has-values="(values[field.ulid] ?? 0) > 0"
            :error="visibleError(field.ulid)"
            :show-errors="errorTarget === field.ulid"
            :pending="reordering"
            @submit="errorTarget = field.ulid"
            @move="step"
            @dragstart="dragStart"
            @drop="drop"
        />
    </ul>

    <p v-else class="mt-6 text-sm text-ink-muted">{{ t('container.fields.empty') }}</p>

    <section v-if="can.manage" class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('container.fields.create_heading') }}</h2>

        <p v-if="atLimit" class="mt-2 text-sm text-ink-muted">
            {{ atLimitText }}
        </p>

        <form
            v-else
            class="mt-4 flex flex-wrap items-end gap-3"
            @submit.prevent="create"
        >
            <FormField
                v-slot="{ describedBy }"
                :label="t('container.fields.label')"
                id="new-field-label"
                :error="errorTarget === 'new' ? page.props.errors.label : null"
            >
                <input
                    id="new-field-label"
                    v-model="createForm.label"
                    :aria-describedby="describedBy"
                    type="text"
                    name="label"
                    required
                    maxlength="100"
                    class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.fields.type')"
                id="new-field-type"
                :error="errorTarget === 'new' ? page.props.errors.type : null"
            >
                <FieldTypeSelect
                    id="new-field-type"
                    v-model="createForm.type"
                    :types="types"
                    :described-by="describedBy"
                />
            </FormField>

            <UiButton type="submit" :pending="createForm.processing">
                {{ createForm.processing ? t('common.pending.default') : t('container.fields.create') }}
            </UiButton>
        </form>

        <!-- Gränsen och rubrikkrocken ur en nekad skrivning, vid formuläret
             som skickades. -->
        <p
            v-if="fieldError && errorTarget === 'new'"
            role="alert"
            class="mt-4 rounded border border-danger bg-surface px-3 py-2 text-sm text-danger"
        >
            {{ fieldError }}
        </p>
    </section>
</template>

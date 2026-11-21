<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { canDropOn, parentOptions } from './categoryTree.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * En nod i kategoriträdet, med sitt EGET formulär och sin EGEN PATCH — se
 * issue 56a § Beslut 1, 3 och 5.
 *
 * Egen komponent och inte en v-for i Categories.vue, av samma skäl som
 * 53c:s AccountSettingsForm och 55a:s ContainerAccessRow: ett valideringsfel
 * på en rad färgar annars alla andra röda, och ett `form.processing` hade låst
 * varje spara-knapp i trädet.
 *
 * **En flytt görs med ett drag eller med väljaren** (Beslut 3, issue 1484).
 * Raden bär ett draghandtag över `md:` — webbläsarens HTML5-drag, samma grep
 * som Containers/Fields.vue och OverviewPanelFrame.vue, inget nytt paket — och
 * väljaren och positionsfältet står kvar som vägen för tangentbordet och för
 * skärmar där man inte kan dra. Handtaget är `aria-hidden`: det går inte att nå
 * med tabb, och en knapp utan handling hade varit ett tabbsteg som inte gör
 * något.
 *
 * **Ett släpp på raden gör den till förälder** och lägger den dragna kategorin
 * sist bland barnen. Sidan äger draget — raden vet inte vilka grannar den har
 * — och räknar ut förälder och position med movePayload() i categoryTree.js.
 * Raden markerar sig som mål bara när canDropOn() säger ja, och ett fel ur
 * servern (`category.cycle`, `category.max_depth_exceeded`) ritas här, på den
 * rad som drogs.
 *
 * **`parent` skickas ALLTID**, också när den är `null` — se
 * App\Http\Controllers\CategoryController::update() och Beslut 5. Väljaren har
 * alltid ett valt värde, så servern anropar `MoveCategory` villkorslöst.
 *
 * Väljaren erbjuder inte kategorin själv eller någon av dess ättlingar, men
 * filtreringen är en artighet: `MoveCategory` prövar cykeln och djupet på
 * servern, och dess svar är det som gäller (Beslut 3).
 *
 * **Radera är en knapp, inte ett eget formulär**: rutten är en DELETE och
 * Inertia skickar CSRF-tokenet åt oss, samma mönster som ContainerAccessRow.
 * Ingen knapp döljs — behörighetskontroller görs i policies, aldrig genom att
 * gömma en knapp.
 *
 * **Sedan issue 150 frågar raden först, och kategorin raderas med sitt
 * underträd.** `router.delete` och inte Inertias länkkomponent med
 * `method`-attributet, av exakt samma skäl som ItemAttachmentSection.vue: en
 * bekräftelse måste kunna AVBRYTA navigeringen, och det kan bara en
 * `window.confirm()` före anropet. Talen kommer ur `counts`-proppen, samma
 * uppslag ULID → tal som taggsidan bär: antalet levande underkategorier i
 * underträdet och antalet items som sitter på kategorin eller någon av dem.
 *
 * **Frågan ställs bara när det finns något att varna för.** En kategori utan
 * underkategorier och utan items raderas utan fråga, som i dag — där finns
 * ingenting att ångra som användaren inte redan ser. Formuleringen väljs i
 * tre steg (barn och items, bara barn, bara items) eftersom `t()` inte
 * pluraliserar och en mening som säger "används på 0 items" vore sämre än
 * ingen mening alls; nycklarna bor i `lang/en/ui.php`.
 *
 * **Nekas raderingen ritas felet HÄR**, vid den berörda raden (Beslut 4).
 * Felpåsen bär felet på formulärnyckeln `category` och kan inte säga vilken rad
 * det gäller, så sidan håller reda på ULID:n för den rad vars radering senast
 * skickades och skickar den hit. Ett träd om fem nivåer kan ha många noder som
 * heter något kort: "Kategorin har 3 items och kan inte raderas" högst upp
 * lämnar användaren att gissa vilken av dem som nekades. Sedan issue 150
 * nekande raderingar är borta ur den här ytan, men rutan står kvar för de
 * domänfel som fortfarande kan komma ur rutten.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    category: { type: Object, required: true },
    categories: { type: Array, required: true },
    /* ULID → { subcategories, items }, ur kontrollerns `counts`-prop. */
    counts: { type: Object, required: true },
    /* ULID:n för den rad vars radering senast skickades, eller null. */
    deleteErrorUlid: { type: String, default: null },
    /* ULID:n för den rad som dras just nu, eller null — sidan äger draget. */
    draggingUlid: { type: String, default: null },
    /* ULID:n för den rad vars flytt senast nekades av servern, eller null. */
    moveErrorUlid: { type: String, default: null },
    /* Sant medan en flytt skrivs: radens egna knappar vilar då. */
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['delete', 'dragstart', 'dragend', 'drop', 'submit']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const deleteFailed = computed(() => props.deleteErrorUlid === props.category.ulid);

/* ULID:n för den nod vars radering väntar på svar, annars null — knappen
   stängs medan den väntar, så samma nod inte kan skickas två gånger. */
const deleting = ref(null);

/* Raden är ett giltigt mål för det pågående draget. */
const isDropTarget = computed(
    () => props.draggingUlid !== null
        && canDropOn(props.categories, props.draggingUlid, props.category.ulid),
);

/* Talen för DEN HÄR raden. Saknas raden i uppslaget finns inget att varna för. */
const tally = computed(() => props.counts[props.category.ulid] ?? { subcategories: 0, items: 0 });

const destroyConfirm = computed(() => {
    const { subcategories, items } = tally.value;

    if (subcategories > 0 && items > 0) {
        return t('container.categories.destroy_confirm_tree', { subcategories, items });
    }

    if (subcategories > 0) {
        return t('container.categories.destroy_confirm_tree_empty', { subcategories });
    }

    return t('container.categories.destroy_confirm', { items });
});

/* Fältens id:n måste vara unika i trädet — varje nod har samma fältnamn. */
const field = (name) => `category-${props.category.ulid}-${name}`;

const options = computed(() => parentOptions(props.categories, props.category.ulid));

const form = useForm({
    name: props.category.name,
    position: props.category.position,
    parent: props.category.parent,
});

/*
 * `useForm` läser propparna EN gång, men en flytt kan ändra dem under raden:
 * en kategori som byter förälder monteras om (den hamnar under en annan
 * `<li>` och får ett färskt formulär), men en rotkategori som släpps på
 * *Top level* stannar i samma `<li>` — servern räknar om `position` och raden
 * behåller sitt gamla värde. En senare Save skickade då tillbaka den gamla
 * siffran och ångrade flytten utan att något syntes. Därför synkas de två
 * fält som flytten rör när propparna ändras, och `defaults()` följer med så
 * att formuläret inte ser sig som ändrat.
 *
 * Namnet rörs aldrig: det kan vara osparat när sidan ritar om efter en annan
 * rads PATCH. Jämförelsen mot förra proppvärdet gör att en osparad
 * position/förälder inte heller skrivs över av en omladdning som inte ändrade
 * något.
 */
watch(
    () => ({ position: props.category.position, parent: props.category.parent }),
    (nu, forra) => {
        if (nu.position === forra.position && nu.parent === forra.parent) {
            return;
        }

        form.position = nu.position;
        form.parent = nu.parent;

        form.defaults({ position: nu.position, parent: nu.parent });
    },
);

function submit() {
    /* Sidan äger `moveErrorUlid`, och ett fel ur ett tidigare drag hör inte
       till den här begäran. Säg till innan PATCH:en går, så den gamla rutan
       inte står kvar på fel rad om servern nekar också den här sparningen. */
    emit('submit', props.category.ulid);

    form.patch(`/containers/${props.containerUlid}/categories/${props.category.ulid}`, {
        preserveScroll: true,
        onError: focusFirstError,
    });
}

function destroy() {
    const { subcategories, items } = tally.value;

    if ((subcategories > 0 || items > 0) && ! window.confirm(destroyConfirm.value)) {
        return;
    }

    emit('delete', props.category.ulid);

    router.delete(`/containers/${props.containerUlid}/categories/${props.category.ulid}`, {
        preserveScroll: true,
        onStart: () => { deleting.value = props.category.ulid; },
        onFinish: () => { deleting.value = null; },
    });
}

/*
 * Draget: handtaget sätter radens ULID i dataTransfer (webbläsaren kräver ett
 * dataobjekt för att starta det) och säger `dragstart`; sidan håller redan
 * reda på vilken rad det gäller. `dragover` släpps bara igenom på ett giltigt
 * mål, så markören säger samma sak som markeringen.
 */
function onDragStart(event) {
    event.dataTransfer?.setData('text/plain', props.category.ulid);

    if (event.dataTransfer !== null) {
        event.dataTransfer.effectAllowed = 'move';
    }

    emit('dragstart', props.category.ulid);
}

function onDragOver(event) {
    if (! isDropTarget.value) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer !== null) {
        event.dataTransfer.dropEffect = 'move';
    }
}

function onDrop(event) {
    if (! isDropTarget.value) {
        return;
    }

    event.preventDefault();
    emit('drop', props.category.ulid);
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded border border-slate-300 bg-white p-4"
        :class="{ 'ring-2 ring-blue-600': isDropTarget }"
        @dragover="onDragOver"
        @drop="onDrop"
    >
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="submit">
            <!-- Handtaget: draget börjar här och inte på hela raden, så att
                 fälten i raden går att markera som vanligt. Under md: ritas
                 det inte — väljaren är vägen där. -->
            <span
                draggable="true"
                aria-hidden="true"
                class="hidden min-h-11 cursor-grab items-center px-1 text-slate-400 md:flex"
                :title="t('container.categories.drag')"
                @dragstart="onDragStart"
                @dragend="emit('dragend')"
            >⠿</span>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.name')"
                :id="field('name')"
                :error="form.errors.name"
            >
                <input
                    :id="field('name')"
                    v-model="form.name"
                    :aria-describedby="describedBy"
                    type="text"
                    name="name"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.parent')"
                :id="field('parent')"
                :error="form.errors.parent"
            >
                <select
                    :id="field('parent')"
                    v-model="form.parent"
                    :aria-describedby="describedBy"
                    name="parent"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option :value="null">{{ t('container.categories.parent_root') }}</option>
                    <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                        {{ option.name }}
                    </option>
                </select>
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('container.categories.position')"
                :id="field('position')"
                :error="form.errors.position"
            >
                <input
                    :id="field('position')"
                    v-model="form.position"
                    :aria-describedby="describedBy"
                    type="number"
                    name="position"
                    class="w-24 rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <button
                type="submit"
                :disabled="form.processing || disabled"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('container.categories.save') }}
            </button>
        </form>

        <button
            type="button"
            :disabled="deleting !== null || disabled"
            class="inline-flex min-h-11 items-center self-start text-sm text-red-700 underline disabled:opacity-50"
            @click="destroy"
        >
            {{ t('container.categories.destroy') }}
        </button>

        <!-- Ett fel ur serverns flyttprövning — cykeln eller djupet — ritas
             här, på raden som drogs. Felpåsen kan inte säga vilken rad det
             gäller, så sidan minns ULID:n för den senaste dragna raden. -->
        <p
            v-if="moveErrorUlid === category.ulid && page.props.errors.parent"
            role="alert"
            class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.parent }}
        </p>

        <p
            v-if="deleteFailed && page.props.errors.category"
            role="alert"
            class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.category }}
        </p>
    </div>
</template>

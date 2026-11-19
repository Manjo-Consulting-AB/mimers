<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue';
import ContainerCover from '../../components/ContainerCover.vue';
import ContainerAgentsSection from '../../components/ContainerAgentsSection.vue';
import ContainerCoverSheet from '../../components/ContainerCoverSheet.vue';
import CurrencyField from '../../components/CurrencyField.vue';
import FormField from '../../components/FormField.vue';
import ItemIcon from '../../components/ItemIcon.vue';
import ItemIconPicker from '../../components/ItemIconPicker.vue';
import ApplyTemplateDialog from '../../components/templates/ApplyTemplateDialog.vue';
import SaveTemplateDialog from '../../components/templates/SaveTemplateDialog.vue';
import UiConfirmDialog from '../../components/UiConfirmDialog.vue';
import UiIcon from '../../components/UiIcon.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container, can: props.can }],
        [ContainerSettingsLayout, { container: props.container }],
    ],
});

/*
 * Containerns inställningar, se issue 54 § Beslut 7, 8 och 9 och issue 62b
 * § Beslut 4, 5 och 6.
 *
 * Sidan deklarerar sin kedja — `AppLayout`, `ContainerLayout`,
 * `ContainerSettingsLayout` — och bär den prop leden kräver: `container` ur
 * App\Http\Resources\ContainerResource (issue 275).
 *
 * FORMULÄRETS PATCH mot /containers/{ulid} bär `name`, `kind`, `description` —
 * sedan issue 88 · [[ADR-0039 Containerns översikt]] — och `currency` (issue
 * 85 · [[ADR-0037 Valutans arv]]). `UpdateContainerRequest` tar
 * inte emot något annat, så ett `account`-fält här hade varit en yta som inte
 * gör något. Att flytta en container mellan konton är ägarbyte (issue 39),
 * inte en inställning. Sidan gör en PATCH till — ikonvalets (se nedan), som
 * bär bara `icon` och `icon_color`.
 *
 * **Beskrivningen är ett fritextfält och ingenting mer.** Den är frivillig,
 * den får sättas, ändras och tömmas, och den visas ordagrant — ingen
 * presentation byggs ur innehållet, och ingen kod plockar isär det. Modell,
 * årtal och tillverkare byggs inte ([[ADR-0033 Produktens omfång]]).
 *
 * Ingen egen ägarkontouppgift i vyn: den här sidan handlar om containern, och
 * delningsstatus hör till listan.
 *
 * Typ-listan kommer som prop (Beslut 8), aldrig en avskrift i JavaScript.
 * Sedan issue 84 · [[ADR-0036 Containerns art]] bär den användarens REDAN
 * ANVÄNDA arter och inte en fast mängd: fältet är fritt, `datalist` ger
 * autocomplete, och den som vill tömma det får det. SAMMA lista som skapavyn
 * får, ur samma servermetod. `kind` är presentation och bara presentation:
 * ingen gren i den här vyn läser värdet.
 *
 * **Raderingsknappen kom med 62b § Beslut 4 och bor HÄR, aldrig i listan.**
 * Det här är sidan där man ändrar containern, och därför också där man tar bort
 * den; en raderingsknapp i listan, bredvid *Gör aktiv*, är en felklickning
 * från att containern försvinner. Den ritas ur `can.delete`, som kontrollern
 * räknar med samma grind som rutten prövar (Beslut 6) — flaggan är
 * presentation, och en delegerad åtkomst som postar förbi vyn får 403.
 *
 * **Bekräftelsen är `window.confirm` med serverns mening ur `lang/`**
 * (Beslut 5), samma mönster som itemets radering (57b § Beslut 8) — ingen
 * modal komponent. Meningen bär containerns namn och säger tre saker: att allt i
 * containern följer med, att den ligger kvar i papperskorgen i 30 dagar, och att
 * den går att återställa därifrån. Den säger INTE "raderas permanent", vilket
 * vore osant — raderingen är mjuk (issue 8).
 *
 * **Sidan är containerns samlingsplats sedan issue 101** ·
 * [[ADR-0042 Designsystemet]]. Flikraden ritar de ytor man arbetar i, och de
 * sju sektionerna som inte fick plats där — kategorier, taggar, delning,
 * kalender, export, papperskorg och överlåtelse — listades här i stället, som
 * länkar med samma etikett och samma adress som de hade i sektionsmenyn.
 *
 * **Listan bor i `ContainerSettingsLayout` och inte här.** Den låg under
 * formuläret och fanns därför bara på den här sidan; de sex andra nåddes bara
 * via den. Nu är den en sidokolumn som står på alla åtta sidor, ritad ur
 * samma `containerSettingsSections` — en ny sektion är en ny rad i
 * containerSections.js och ingen ändring i vare sig layouten eller den här
 * filen.
 *
 * **Ingen rad får försvinna, och det är därför kolumnen finns.** En yta ingen
 * hittar är samma sak som en yta som inte finns — det var 62a:s motivering för
 * papperskorgen och 67c:s för exporten, och de två är just de rader som hade
 * varit lätta att tappa när menyn blev en flikrad.
 *
 * **Sidan bär två grindar och ritas därför i två delar** (issue 101). Rutten
 * auktoriserar med `view`, för sektionslistan är `view`-innehåll — varje länk i
 * den är `view`-grindad — och den som bara får läsa måste nå dem; hade hubben
 * legat bakom `update` hade hon tappat sex ytor. Formuläret och raderingsknappen
 * är skrivningar och ritas ur `can.update` respektive `can.delete`. Flaggan är
 * presentation och ingen grind: `PATCH` och `DELETE` prövar `update()` och
 * `delete()` som förut, och en `read`-mottagare som postar förbi vyn får 403.
 * Rubriken och sidtiteln följer samma gren som formuläret — att mötas av
 * *Redigera container* när man inte får redigera är en osanning.
 *
 * **Bildavsnittet kom med issue 159 · [[ADR-0047 Containerns bild]] § Beslut**
 * och är den andra av de två vägarna till samma val: pennan på bilden i
 * containerns topprad (resources/js/layouts/ContainerLayout.vue sedan issue 275,
 * teleporterad in i skalet) och det här avsnittet öppnar SAMMA ark ur SAMMA
 * komponent,
 * `ContainerCoverSheet`. Att avsnittet ligger HÄR och inte i formuläret är
 * med flit: bilden är inte ett fält i `PATCH /containers/{container}` utan sin
 * egen rutt, och att blanda in den i formulärets `useForm` hade gett en
 * sparning två mål. Det ritas ur `can.update`, samma flagga som formuläret —
 * den som bara får läsa ser varken pennan eller avsnittet, och rutten prövar
 * `ContainerPolicy::update` på nytt.
 *
 * **Ikonavsnittet kom med issue 315 · [[ADR-0064 Containerns ikon]] § 3 och
 * 4, och sparar direkt sedan Tonys fynd 2026-11-14.** Regeln för sidan är att
 * ett val i en väljare sparas direkt och text sparas med *Save*: ikonen
 * fungerar som containerns bild i avsnittet under, inte som formulärets
 * textfält. Det är samma väljare som itemets formulär använder, med samma
 * ark, samma sökfält och samma *Remove icon*, men valet skrivs inte i
 * formuläret — varje klick skickar sin egen PATCH med bara `icon` och
 * `icon_color`, och `preserveState` håller osparade fält kvar. Den visade
 * ikonen läses ur `props.container`, alltså serverns svar. Utan vald ikon
 * ritas containerns roll `container` i den grå rutan — inte itemets, som är
 * `ItemIcon`:s fallback tills proppen `fallback` kommer i issue 316.
 * Sektionen ritas ur `can.update`, samma flagga som formuläret och bilden.
 */
const props = defineProps({
    container: { type: Object, required: true },
    /* Användarens redan använda arter — underlag för autocomplete, inte en
       tillåten mängd. Fältet är fritt och får tömmas. */
    kinds: { type: Array, required: true },
    /* Containerns EGEN valuta, `null` när den ärver kontots (issue 85). */
    currency: { type: String, default: null },
    /* Ägarkontots valuta — det containern faller tillbaka på. */
    accountCurrency: { type: String, required: true },
    can: { type: Object, required: true },
    /* Avsnittet *Agents* (issue 408), `null` utan Pro hos båda eller med MCP av. */
    agents: { type: Object, default: null },
    /* Versionshistorikens tak (issue 411 · [[ADR-0088 Versionshistorik för
       items]] Riktning 1): `{max, accountLimit}`. `max` är containerns EGET
       värde — `null` är *Follow account* — och `accountLimit` är kontots tak
       som valen ritar sig ur. */
    versioning: { type: Object, required: true },
    /* Issue 422 · containerns kontos mallar, ur
       App\Http\Resources\ContainerTemplateResource — valet i *Apply template*.
       Innehållet följer med så dialogen kan förhandsvisa det. */
    templates: { type: Array, required: true },
    /* Rubrikerna som redan syns på containerns items — det förhandsvisningen
       jämför mot, så *Apply template* kan säga vad som hoppas över INNAN det
       tillämpas. Samma mängd som serverns förkontroll läser. */
    visibleFieldLabels: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    name: props.container.name,
    // En container skapad utan art bär `null`; rutan ska vara tom, inte visa
    // ordet "null" (issue 84).
    kind: props.container.kind ?? '',
    // En container skapad utan beskrivning bär `null`; rutan ska vara tom. En
    // TÖMD ruta sparar `null` igen — fältet är frivilligt hela vägen (issue
    // 88 · [[ADR-0039 Containerns översikt]]).
    description: props.container.description ?? '',
    // Samma sak för valutan: en tom ruta betyder "ärv kontots", och det är
    // ett giltigt svar — inte ett fält användaren glömt (issue 85 ·
    // [[ADR-0037 Valutans arv]]).
    currency: props.currency ?? '',
});

// Ikonväljarens tillstånd (issue 315, direktsparning sedan Tonys fynd
// 2026-11-14). Väljaren får det öppna läget och öppnarens element; valet bor
// inte i `form`, utan skickas i en egen PATCH och läses tillbaka ur
// `props.container`. `iconPending` stänger *Choose icon* medan anropet är i
// luften, och statusraden säger vad som hände — valet syns redan i rutan
// bredvid, så det är ett besked och ingen bekräftelsedialog.
const iconPickerOpen = ref(false);
const iconTrigger = ref(null);
const iconPending = ref(false);
const iconStatus = ref(null);
const iconError = ref(null);

function openIconPicker(element) {
    iconTrigger.value = element;
    iconPickerOpen.value = true;
}

/*
 * Ett val sparas direkt: en PATCH med BARA `icon` och `icon_color`.
 * `UpdateContainerRequest` har `sometimes` på varje fält, så servern rör
 * inget annat — namnet, arten, beskrivningen och valutan användaren skriver
 * i formuläret ligger kvar orörda. `preserveState` håller dem också kvar i
 * vyn, så ett ikonval aldrig nollställer osparade ändringar.
 */
function chooseIcon({ icon, color }) {
    iconStatus.value = null;
    iconError.value = null;

    router.patch(`/containers/${props.container.ulid}`, { icon, icon_color: color }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            iconPending.value = true;
        },
        onFinish: () => {
            iconPending.value = false;
        },
        onSuccess: () => {
            iconStatus.value = 'saved';
        },
        onError: (errors) => {
            iconError.value = errors.icon ?? errors.icon_color ?? null;
        },
    });
}

// En färg utan ikon är inget val, så *Remove icon* tömmer båda — samma regel
// som i `ItemForm`. Anropet är valets eget och bär de två `null` som
// `UpdateContainerRequest` säger `sometimes|nullable` om.
function removeIcon() {
    iconStatus.value = null;
    iconError.value = null;

    router.patch(`/containers/${props.container.ulid}`, { icon: null, icon_color: null }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            iconPending.value = true;
        },
        onFinish: () => {
            iconPending.value = false;
        },
        onSuccess: () => {
            iconStatus.value = 'removed';
        },
        onError: (errors) => {
            iconError.value = errors.icon ?? errors.icon_color ?? null;
        },
    });
}

// --- Valutor för kostnader (issue 1254 · ADR-0078 § 4 och 5) ---------------
//
// Avsnittet ligger efter ikonen och bilden, och varje ändring sparas DIREKT
// (Tonys regel 2026-11-14, #1245): ett chip som läggs till eller tas bort
// skickar hela den nya listan med PUT, och formulärets text ligger kvar
// osparad. Ingen *Save* hör hit — valet är en väljare, inte text.
//
// Urvalet läses ur `container.allowed_currencies` (ContainerResource).
// Standardvalutan står alltid med som chip när ett urval finns — den är
// alltid tillåten utan att stå i urvalet (ADR-0078 § 4). *Remove* hänger på
// om koden står i det sparade urvalet, inte på om den är standardvaluta:
// annars gick urvalet inte att tömma när standardvalutan också valts, eller
// efter ett byte av containerns valuta. Ett tomt urval betyder alla valutor
// och ritas som en mening.
const page = usePage();

const defaultCurrency = computed(() => props.currency ?? props.accountCurrency);

const selectedCurrencies = computed(() => props.container.allowed_currencies ?? []);

/* Namnen ur den delade, LATA `currencies`-proppen — samma som CurrencyField
   använder. Saknas den visas bara koden, och avsnittet fungerar ändå. */
const currencyNames = computed(() => Object.fromEntries(
    (page.props.currencies ?? []).map((option) => [option.code, option.name]),
));

function currencyLabel(code) {
    const name = currencyNames.value[code];

    return name && name !== code ? `${code} · ${name}` : code;
}

/* Chipsen: urvalet PLUS standardvalutan, utan dubbletter. */
const shownCurrencies = computed(() => (selectedCurrencies.value.length === 0
    ? []
    : [...new Set([...selectedCurrencies.value, defaultCurrency.value])].sort()));

/*
 * Namnen hämtas bara när det finns ett urval att sätta namn på. Utan urval
 * ritar avsnittet meningen *All currencies can be used* och behöver dem inte;
 * CurrencyField hämtar listan själv när den får fokus.
 */
function loadCurrencyNames() {
    if (selectedCurrencies.value.length > 0 && page.props.currencies === undefined) {
        router.reload({ only: ['currencies'] });
    }
}

onMounted(loadCurrencyNames);

const currencyToAdd = ref('');
const currenciesPending = ref(false);
const currenciesStatus = ref(null);
const currenciesError = ref(null);

/*
 * Hela listan skickas varje gång — servern ersätter urvalet och svarar med
 * containern. `preserveState` håller formulärets osparade text kvar, precis
 * som ikonvalet gör (issue 315), och statusraden säger vad som hände.
 */
function saveCurrencies(codes) {
    currenciesStatus.value = null;
    currenciesError.value = null;

    router.put(`/containers/${props.container.ulid}/currencies`, { currencies: codes }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            currenciesPending.value = true;
        },
        onFinish: () => {
            currenciesPending.value = false;
        },
        onSuccess: () => {
            currenciesStatus.value = 'saved';
            currencyToAdd.value = '';
            loadCurrencyNames();
        },
        onError: (errors) => {
            currenciesError.value = errors.currencies ?? errors['currencies.0'] ?? null;
            currencyToAdd.value = '';
        },
    });
}

/* Ett val i CurrencyField sparas direkt. Standardvalutan läggs aldrig till i
   urvalet — den är alltid tillåten och står redan som chip — och samma valuta
   två gånger är inget nytt urval. Inget av dem skickar något. */
watch(currencyToAdd, (code) => {
    if (code === '' || currenciesPending.value) {
        return;
    }

    if (code === defaultCurrency.value || selectedCurrencies.value.includes(code)) {
        currencyToAdd.value = '';

        return;
    }

    saveCurrencies([...selectedCurrencies.value, code]);
});

function removeCurrency(code) {
    saveCurrencies(selectedCurrencies.value.filter((currency) => currency !== code));
}

// --- Versionshistorik (issue 411 · ADR-0088 Riktning 1) --------------------
//
// Containerns tak för versionshistoriken. Valet sparas DIREKT — en väljare,
// inte text, så ingen *Save* (Tonys regel 2026-11-14, #1245) — och
// formulärets osparade text ligger kvar.
//
// *Follow account* är `null`, *Off* är 0 och talen går upp till kontots tak.
// Att SÄNKA värdet eller stänga av tar bort överskottet (issue 414), så det
// valet frågar först med `UiConfirmDialog`. En höjning frågar aldrig.
const versioningPending = ref(false);
const versioningStatus = ref(null);
const versioningError = ref(null);
const versioningConfirmOpen = ref(false);
const versioningTrigger = ref(null);
const versioningChoice = ref(undefined);

/* Containerns lagrade värde kan ligga ÖVER kontots tak: det händer när kontots
   tak sänkts efter att containern fick sitt värde, och containerraden ska då
   stå kvar orörd. Alternativen går bara upp till kontots tak, så ett lagrat
   värde utanför dem ritas inte alls av `<select>`. Väljaren visar därför det
   tak som verkligen gäller — det lägre av de två. */
const selectedVersioning = computed(() => {
    const own = props.versioning.max;

    if (own === null) {
        return 'follow';
    }

    return String(Math.min(own, props.versioning.accountLimit));
});

/* Vad som gäller i dag, räknat som App\Support\Versioning\VersionLimit::for():
   0 på endera nivån ger 0, annars det lägre av kontots och containerns. */
const effectiveVersioning = computed(() => {
    const own = props.versioning.max;
    const account = props.versioning.accountLimit;

    if (account === 0) {
        return 0;
    }

    if (own === null) {
        return account;
    }

    if (own === 0) {
        return 0;
    }

    return Math.min(account, own);
});

function onVersioningChange(event) {
    const raw = event.target.value;
    const value = raw === 'follow' ? null : Number(raw);

    versioningStatus.value = null;
    versioningError.value = null;
    versioningTrigger.value = event.target;

    const lowers = value !== null && (value === 0 || value < effectiveVersioning.value);

    if (! lowers) {
        saveVersioning(value);

        return;
    }

    versioningChoice.value = value;
    versioningConfirmOpen.value = true;
}

function confirmVersioning() {
    versioningConfirmOpen.value = false;
    saveVersioning(versioningChoice.value);
}

/* Avbrottet sätter tillbaka väljaren till det sparade värdet: `:value` bindes
   mot proppen, och proppen ändrades aldrig. */
function cancelVersioning() {
    versioningConfirmOpen.value = false;

    if (versioningTrigger.value) {
        versioningTrigger.value.value = selectedVersioning.value;
    }
}

function saveVersioning(value) {
    router.patch(`/containers/${props.container.ulid}/versioning`, { versioning_max_versions: value }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            versioningPending.value = true;
        },
        onFinish: () => {
            versioningPending.value = false;
        },
        onSuccess: () => {
            versioningStatus.value = 'saved';
        },
        onError: (errors) => {
            versioningError.value = errors.versioning_max_versions ?? null;

            // Servern nekade valet: väljaren går tillbaka till det sparade
            // värdet, så vyn inte visar ett tak som inte gäller.
            if (versioningTrigger.value) {
                versioningTrigger.value.value = selectedVersioning.value;
            }
        },
    });
}

// --- Mallar (issue 422 · ADR-0089 Riktning 6) ------------------------------
//
// *Save as template* frågar efter namnet i en dialog och postar till
// `/containers/{ulid}/templates`; *Apply template* väljer bland kontots mallar
// och visar vad som läggs till och hoppas över innan anropet. Båda använder
// `useForm`, så vänteläget är formens `processing` och en dubbelpostning är
// stängd.
const saveTemplateForm = useForm({ container: props.container.ulid, name: '' });
const saveTemplateOpen = ref(false);
const saveTemplateTrigger = ref(null);

function openSaveTemplate(event) {
    saveTemplateTrigger.value = event.currentTarget;
    saveTemplateOpen.value = true;
}

function saveTemplate(name) {
    saveTemplateForm.container = props.container.ulid;
    saveTemplateForm.name = name;

    saveTemplateForm.post(`/containers/${props.container.ulid}/templates`, {
        preserveScroll: true,
        onSuccess: () => {
            saveTemplateOpen.value = false;
        },
    });
}

const applyTemplateForm = useForm({ template: null });
const applyTemplateOpen = ref(false);
const applyTemplateTrigger = ref(null);

function openApplyTemplate(event) {
    applyTemplateTrigger.value = event.currentTarget;
    applyTemplateOpen.value = true;
}

function applyTemplate(ulid) {
    applyTemplateForm.template = ulid;

    applyTemplateForm.post(`/containers/${props.container.ulid}/template`, {
        preserveScroll: true,
        onSuccess: () => {
            applyTemplateOpen.value = false;
        },
    });
}

// Raderingen är ett router.anrop och inte ett useForm-formulär, så vänteläget
// bärs av en egen flagga (issue 68a § Beslut 5).
const pending = ref(false);

function submit() {
    form.patch(`/containers/${props.container.ulid}`, { onError: focusFirstError });
}

// `await` i en try/finally i stället för Inertias `onStart`/`onFinish`:
// anropet är låst till formen `router.delete(url)` av
// ContainerpapperskorgTest, och ett options-objekt hade tvingat fram en
// uppluckring av det testet (Beslut 7). Flaggan sätts när anropet lämnar
// klienten och nollställs när svaret kommit, fel eller ej — samma sak.
async function destroy() {
    if (! window.confirm(t('container.destroy.confirm', { name: props.container.name }))) {
        return;
    }

    pending.value = true;

    try {
        await router.delete(`/containers/${props.container.ulid}`);
    } finally {
        pending.value = false;
    }
}
</script>

<template>
    <!-- Sidtiteln och rubriken följer samma gren som formuläret: *Redigera
         container* åt någon som inte får redigera är en osanning, och den
         som kommer hit för sektionernas skull möts av listans namn i
         stället. `title`/`heading`-paret är det befintliga och betyder
         samma sak här; båda grenarna finns i `lang/`. -->
    <Head :title="can.update ? t('container.edit.title') : t('container.edit.sections')" />

    <h1 class="text-2xl font-semibold">
        {{ can.update ? t('container.edit.heading') : t('container.edit.sections') }}
    </h1>

    <form v-if="can.update" class="mt-8 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('container.edit.name')"
            id="name"
            :error="form.errors.name"
        >
            <input
                id="name"
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
            :label="t('container.edit.kind')"
            id="kind"
            :error="form.errors.kind"
        >
            <!-- Fritext med autocomplete, inte en väljare: värdet är
                 användarens eget, och kontots arter är förslag. Att
                 tömma fältet är ett giltigt svar. -->
            <input
                id="kind"
                v-model="form.kind"
                :aria-describedby="describedBy"
                type="text"
                name="kind"
                list="container-kinds"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
            <datalist id="container-kinds">
                <option v-for="kind in kinds" :key="kind" :value="kind" />
            </datalist>
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('container.edit.description')"
            id="description"
            :error="form.errors.description"
        >
            <!-- Ett enda fritextfält, frivilligt hela vägen: det får
                 sättas, ändras och TÖMMAS (issue 88 · [[ADR-0039
                 Containerns översikt]]). Ingen struktur och ingen
                 hjälprad som ber om modell eller årtal — fältet visas
                 som det skrivs, och ingen kod plockar isär det. -->
            <textarea
                id="description"
                v-model="form.description"
                :aria-describedby="describedBy"
                name="description"
                rows="4"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('container.edit.currency')"
            id="currency"
            :error="form.errors.currency"
        >
            <!-- Containerns egen valuta, se issue 85 · [[ADR-0037 Valutans
                 arv]]. Fältet är FÖRIFYLLT och ändringsbart, aldrig dolt:
                 rutan visar vad containern står på och vad en ny
                 kostnadsrad föreslås i. Sedan issue 1253 är det en sökruta
                 över de aktiva ISO 4217-koderna ([[ADR-0078 Valutor ur ISO
                 4217]] § 2), och en tom ruta är fortfarande svaret "ärv
                 kontots" — därför `allow-empty`, och därför står kontots
                 valuta i hjälptexten. -->
            <CurrencyField
                id="currency"
                v-model="form.currency"
                :described-by="describedBy"
                allow-empty
            />
        </FormField>

        <p class="-mt-2 text-sm text-slate-600">
            {{ t('container.edit.currency_hint', { currency: accountCurrency }) }}
        </p>

        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-11 items-center self-start rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('container.edit.submit') }}
        </button>
    </form>

    <!--
        Ikonen (issue 315 · [[ADR-0064 Containerns ikon]] § 3 och 4), bredvid
        bilden. Väljaren är samma `ItemIconPicker` som itemets formulär
        använder, men valet sparas direkt — som containerns bild i avsnittet
        under — och inte med formulärets *Save* (Tonys regel 2026-11-14).
        Sektionen ritas ur `can.update` som formuläret och bilden: den som
        bara får läsa har ingen ikon att välja.
    -->
    <section v-if="can.update" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('item.form.icon') }}</h2>

        <div class="mt-4 flex flex-wrap items-center gap-4">
            <!--
                Den valda ikonen i sin färg och sin ruta, ur serverns svar —
                inte ur ett lokalt utkast. Utan val ritas containerns roll i
                den grå rutan i stället.
            -->
            <ItemIcon
                v-if="container.icon"
                :name="container.icon"
                :color="container.icon_color"
                :size="24"
                tile
            />

            <span
                v-else
                aria-hidden="true"
                class="inline-flex h-9 w-9 items-center justify-center rounded-control bg-surface-sunken text-ink-subtle"
            >
                <UiIcon name="container" :size="24" />
            </span>

            <!--
                En rå `<button>` av samma skäl som i `ItemForm`: den skickar
                sitt eget element vidare till väljaren, och väljaren lämnar
                tillbaka fokus dit när arket stängs. Avstängd medan ett val
                sparas — ett andra val innan det första svarat hade blivit två
                skrivningar i rad.
            -->
            <button
                type="button"
                :disabled="iconPending"
                class="inline-flex min-h-11 items-center rounded border border-border px-4 text-sm font-medium text-ink hover:bg-surface-muted disabled:opacity-50"
                @click="openIconPicker($event.currentTarget)"
            >
                {{ t('item.form.icon_choose') }}
            </button>
        </div>

        <!-- Statusraden: valet sparas direkt, och här står vad som hände. Ett
             fel bär `role="alert"`; beskedet `role="status"`. -->
        <p v-if="iconError" role="alert" class="mt-2 text-sm text-danger">{{ iconError }}</p>

        <p v-else-if="iconStatus" role="status" class="mt-2 text-sm text-ink-subtle">
            {{ iconStatus === 'removed' ? t('container.edit.icon_removed') : t('container.edit.icon_saved') }}
        </p>

        <ItemIconPicker
            :open="iconPickerOpen"
            :icon="container.icon"
            :color="container.icon_color"
            :trigger="iconTrigger"
            @choose="chooseIcon"
            @remove="removeIcon"
            @close="iconPickerOpen = false"
        />
    </section>

    <!--
        Bilden (issue 159). Eget avsnitt och inte ett fält i formuläret
        ovan: den har sin egen rutt, och en sparande knapp som gjorde två
        saker hade varit två svar på frågan vad knappen gör.
    -->
    <section v-if="can.update" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('container.cover.heading') }}</h2>

        <p class="mt-1 text-sm text-ink-subtle">{{ t('container.cover.description') }}</p>

        <div class="mt-4 flex flex-wrap items-center gap-4">
            <span class="h-16 w-16 shrink-0 overflow-hidden rounded-control">
                <ContainerCover
                    :cover="container.cover"
                    :icon="container.icon"
                    :color="container.icon_color"
                />
            </span>

            <ContainerCoverSheet :container="container" v-slot="{ open }">
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded border border-border px-4 text-sm font-medium text-ink hover:bg-surface-muted"
                    @click="open"
                >
                    {{ t('container.cover.edit') }}
                </button>
            </ContainerCoverSheet>
        </div>
    </section>

    <!--
        Valutor för kostnader (issue 1254 · [[ADR-0078 Valutor ur ISO 4217]]
        § 4 och 5). Eget avsnitt efter ikonen och bilden, och varje ändring
        sparas direkt med en PUT av hela listan — ingen *Save* (Tonys regel
        2026-11-14, #1245). Standardvalutan står alltid med som chip; den är
        tillåten utan att stå i urvalet. *Remove* visas på varje chip som
        står i det sparade urvalet, även standardvalutan: ett urval som bara
        innehåller standardvalutan måste gå att tömma. Ett tomt urval betyder
        alla valutor och sägs med en mening.
    -->
    <section v-if="can.update" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('container.edit.currencies') }}</h2>

        <p class="mt-1 text-sm text-ink-subtle">{{ t('container.edit.currencies_description') }}</p>

        <p v-if="selectedCurrencies.length === 0" class="mt-4 text-body text-ink">
            {{ t('container.edit.currencies_all') }}
        </p>

        <ul v-else class="mt-4 flex flex-wrap gap-2">
            <li
                v-for="code in shownCurrencies"
                :key="code"
                class="flex items-center gap-2 rounded-control border border-border bg-surface px-3 py-2"
            >
                <span class="text-body text-ink">{{ currencyLabel(code) }}</span>

                <!-- *Remove* hänger på om koden står i det sparade urvalet.
                     En standardvaluta som saknas i urvalet ritas bara som
                     chip: den är alltid tillåten och har inget att ta bort. -->
                <button
                    v-if="selectedCurrencies.includes(code)"
                    type="button"
                    :disabled="currenciesPending"
                    class="inline-flex min-h-11 items-center rounded-control px-2 text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink disabled:opacity-50"
                    @click="removeCurrency(code)"
                >
                    {{ t('container.edit.currencies_remove') }}
                </button>
            </li>
        </ul>

        <p v-if="selectedCurrencies.length > 0" class="mt-2 text-sm text-ink-subtle">
            {{ t('container.edit.currencies_standard', { currency: defaultCurrency }) }}
        </p>

        <div class="mt-4 max-w-sm">
            <label class="text-sm font-medium" for="container-currency-add">
                {{ t('container.edit.currencies_add') }}
            </label>

            <CurrencyField
                id="container-currency-add"
                v-model="currencyToAdd"
                :disabled="currenciesPending"
            />
        </div>

        <p v-if="currenciesError" role="alert" class="mt-2 text-sm text-danger">{{ currenciesError }}</p>

        <p v-else-if="currenciesStatus" role="status" class="mt-2 text-sm text-ink-subtle">
            {{ t('container.edit.currencies_saved') }}
        </p>
    </section>

    <!--
        Mallar (issue 422 · [[ADR-0089 Egna fält på items]] § Riktning 6).
        Två avsnitt och två dialoger: *Save as template* frågar efter namnet,
        *Apply template* väljer bland kontots mallar och visar vad som läggs
        till och hoppas över innan något skrivs.

        *Save as template* ritas ur `can.saveTemplate` — läsrätt i containern
        och medlemskap i dess konto — samma två saker som rutten prövar.
        *Apply template* ritas ur `can.update`, för det lägger till kategorier
        och fält.
    -->
    <section v-if="can.saveTemplate" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('container.templates.save_heading') }}</h2>

        <p class="mt-1 text-sm text-ink-subtle">{{ t('container.templates.save_description') }}</p>

        <button
            type="button"
            class="mt-4 inline-flex min-h-11 items-center rounded border border-border px-4 text-sm font-medium text-ink hover:bg-surface-muted"
            @click="openSaveTemplate"
        >
            {{ t('container.templates.save') }}
        </button>

        <p v-if="saveTemplateForm.errors.name" role="alert" class="mt-2 text-sm text-danger">
            {{ saveTemplateForm.errors.name }}
        </p>

        <SaveTemplateDialog
            :open="saveTemplateOpen"
            :trigger="saveTemplateTrigger"
            :pending="saveTemplateForm.processing"
            :error="saveTemplateForm.errors.name ?? null"
            @save="saveTemplate"
            @close="saveTemplateOpen = false"
        />
    </section>

    <section v-if="can.update" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('container.templates.apply_heading') }}</h2>

        <p class="mt-1 text-sm text-ink-subtle">{{ t('container.templates.apply_description') }}</p>

        <p v-if="templates.length === 0" class="mt-4 text-sm text-ink-subtle">
            {{ t('container.templates.empty') }}
        </p>

        <button
            v-else
            type="button"
            class="mt-4 inline-flex min-h-11 items-center rounded border border-border px-4 text-sm font-medium text-ink hover:bg-surface-muted"
            @click="openApplyTemplate"
        >
            {{ t('container.templates.apply') }}
        </button>

        <p v-if="applyTemplateForm.errors.template" role="alert" class="mt-2 text-sm text-danger">
            {{ applyTemplateForm.errors.template }}
        </p>

        <ApplyTemplateDialog
            :open="applyTemplateOpen"
            :trigger="applyTemplateTrigger"
            :templates="templates"
            :existing-labels="visibleFieldLabels"
            :pending="applyTemplateForm.processing"
            :error="applyTemplateForm.errors.template ?? null"
            @apply="applyTemplate"
            @close="applyTemplateOpen = false"
        />
    </section>

    <!--
        Versionshistorikens tak (issue 411 · [[ADR-0088 Versionshistorik för
        items]] Riktning 1). Avsnittet ritas ur `can.delete`, samma grind som
        raderingen: versionshistoriken är ägarkontots val, och en inbjuden med
        `write` ser det inte. Valet sparas direkt (#1245); att sänka värdet
        eller stänga av frågar först, eftersom överskottet tas bort (issue 414).
    -->
    <section v-if="can.delete" class="mt-12 border-t border-border pt-6">
        <h2 class="text-title">{{ t('container.edit.versioning') }}</h2>

        <p class="mt-1 text-sm text-ink-subtle">{{ t('container.edit.versioning_description') }}</p>

        <div class="mt-4 flex flex-col gap-1">
            <label for="container-versioning" class="text-sm font-medium text-ink">
                {{ t('container.edit.versioning_label') }}
            </label>

            <select
                id="container-versioning"
                :value="selectedVersioning"
                :disabled="versioningPending"
                class="self-start rounded border border-border bg-surface px-3 py-2 text-ink"
                @change="onVersioningChange"
            >
                <option value="follow">
                    {{ t('container.edit.versioning_follow', { count: versioning.accountLimit }) }}
                </option>
                <option value="0">{{ t('container.edit.versioning_off') }}</option>
                <option v-for="value in versioning.accountLimit" :key="value" :value="String(value)">
                    {{ value }}
                </option>
            </select>
        </div>

        <p v-if="versioningError" role="alert" class="mt-2 text-sm text-danger">{{ versioningError }}</p>

        <p v-else-if="versioningStatus" role="status" class="mt-2 text-sm text-ink-subtle">
            {{ t('container.edit.versioning_saved') }}
        </p>

        <UiConfirmDialog
            :open="versioningConfirmOpen"
            :heading="t('container.edit.versioning_confirm_heading')"
            :body="t('container.edit.versioning_confirm_body')"
            :confirm-label="t('container.edit.versioning_confirm_action')"
            tone="danger"
            :pending="versioningPending"
            :trigger="versioningTrigger"
            @confirm="confirmVersioning"
            @cancel="cancelVersioning"
        />
    </section>

    <ContainerAgentsSection v-if="agents" :container-ulid="container.ulid" :section="agents" />

    <div v-if="can.delete" class="mt-12 border-t border-slate-200 pt-6">
        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded border border-red-300 px-4 text-sm font-medium text-red-700 hover:bg-red-50"
            @click="destroy"
        >
            {{ pending ? t('common.pending.default') : t('container.destroy.action') }}
        </button>
    </div>
</template>

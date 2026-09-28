<script setup>
import { computed, ref, useId } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import UiSheet from './UiSheet.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Arket för containerns bild, se [[ADR-0047 Containerns bild]] § Beslut och
 * [[M23 Mobilen och kartan]] § 159.
 *
 * **Ett ark, två vägar.** Pennan på bilden överst i containern
 * (resources/js/layouts/ContainerLayout.vue) och avsnittet under containerns
 * inställningar (resources/js/pages/Containers/Edit.vue) öppnar SAMMA val ur
 * SAMMA komponent. Den ena ytan är den andras form, och en andra upplaga av
 * arket hade varit två ställen att glömma en rad på — samma skäl som
 * `InfoPanel` delas mellan dashboarden och översikten (issue 128).
 *
 * **Anroparen ritar sin egen öppnare, och får `open` i sin slot.** Pennan står
 * PÅ bilden och avsnittet står under ett formulär: formen skiljer sig, och en
 * öppnare inbyggd här hade tvingat den ena ytan att se ut som den andra.
 * `open` tar emot öppnarens element, som arket fäster sig under över `md:` och
 * lämnar tillbaka fokus till (UiSheet § docblock).
 *
 * **Arket används som det är** (issue 152, omfångsrutan): fokusfällan, Esc,
 * trycket utanför och rubriken bor i UiSheet. Här bor bara raderna.
 *
 * **Tre rader, alltid tre.** *Ta ett foto*, *Välj från enheten* och *Ta bort
 * bilden* (ADR-0047 § Beslut). Även på en container utan bild: raden "ta bort"
 * är ett anrop som App\Actions\Container\RemoveContainerCover gör till en
 * no-op, och ett ark vars rader kommer och går är två former av samma ark.
 *
 * **Ingen egen kamera.** *Ta ett foto* är en filväljare med `capture` —
 * telefonens egen kamera svarar, och appen öppnar ingen. Att bygga en
 * kamera-komponent hade varit ett paket och en yta som ingen issue bad om.
 *
 * **Ett fel blir en rad i arket, aldrig en rå kropp.** Kvotgränsen och
 * "filen är ingen bild" kommer från servern som `ApiException` och blir
 * fältfel på `file` i App\Http\Controllers\ContainerCoverController — samma
 * väg som itemets bilageuppladdning. Meningen är redan översatt på servern
 * ([[ADR-0021 Frontendteknik]] § Beslut: en katalog, inte två), så den här
 * filen skriver ingen egen text för den.
 *
 * **Vänteläget ligger på `onStart`/`onFinish`** (issue 68a § Beslut 3), och
 * knapparna inaktiveras medan anropet är i luften — en andra filval innan det
 * första svarat hade blivit två byten i rad, och bara det sista hade synts.
 *
 * **Kontot som betalar skickas med, dolt.** Bytena räknas mot det UPPLADDANDE
 * kontots kvot ([[ADR-0047 Containerns bild]] § Beslut), och förvalet är
 * itemets bilageuppladdnings (resources/js/components/ItemAttachmentSection.vue,
 * issue 60 § Beslut 4): containerns ägarkonto när användaren är medlem i det,
 * annars hennes eget första konto. Arket har fortfarande TRE rader — kontot är
 * inget val någon gör här, och raden syns därför inte. Servern prövar
 * medlemskapet och nekar ett konto användaren inte är medlem i.
 *
 * Ingen sträng står i filen ([[ADR-0013 Språk och i18n]]): varje text kommer
 * ur `t()` med en nyckel under `container.cover.*`.
 */
const props = defineProps({
    /* Containern ur ContainerResource — `ulid` och `cover` läses här. */
    container: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const open = ref(false);
const trigger = ref(null);

/*
 * Kontot som betalar. `auth.accounts` är den inloggades egna konton, alltså
 * exakt dem hon är medlem i — samma lista och samma förval som itemets
 * bilageuppladdning räknar ur (issue 60 § Beslut 4). Är hon medlem i
 * containerns ägarkonto blir det kontot det uppladdande; annars hennes eget
 * första, så en främmande `write`-mottagare aldrig belastar ägarkontot
 * ([[ADR-0017 Missbruksvektorer]]).
 */
const accounts = computed(() => page.props.auth?.accounts ?? []);

const account = computed(() => {
    const owner = accounts.value.find((candidate) => candidate.ulid === props.container.account);

    return owner?.ulid ?? accounts.value[0]?.ulid ?? '';
});

/*
 * Fältens id:n, unika per instans. GenomgangTest § Beslut 3 kräver ett `id` på
 * varje `<input>` i skalet, och arket ritas mer än en gång på samma sida —
 * pennan i skalets topprad och avsnittet på inställningssidan är två instanser
 * — så `useId()` håller dem åtskilda i stället för att två `<input>` delar id.
 *
 * Ingen `<label for>` pekar på dem, och det ska ingen göra: fälten är `hidden`
 * och knapparna nedan är målet. Id:t är formens krav, inte en etikettkoppling.
 */
const uid = useId();
const cameraId = `container-cover-camera-${uid}`;
const deviceId = `container-cover-device-${uid}`;

const cameraInput = ref(null);
const deviceInput = ref(null);

const pending = ref(false);
const error = ref(null);

function show(event) {
    trigger.value = event.currentTarget ?? null;
    error.value = null;
    open.value = true;
}

function close() {
    open.value = false;
}

/*
 * Raden öppnar väljaren. Fälten är `hidden` och aldrig tabbbara: knappen är
 * målet en tumme eller ett tangentbord träffar, och den bär radens ord.
 *
 * Två funktioner och inte en som tar emot reffen: i mallen packas en `ref` upp
 * till sitt värde, så `choose(cameraInput)` hade skickat ELEMENTET till en
 * funktion som väntade en ref — och tyst gjort ingenting.
 */
function chooseCamera() {
    cameraInput.value?.click();
}

function chooseDevice() {
    deviceInput.value?.click();
}

/*
 * En fil vald. Fältet töms efteråt så att SAMMA fil går att välja igen — utan
 * det svarar `change` inte andra gången, och en misslyckad uppladdning hade
 * inte gått att försöka om.
 */
function onSelected(event) {
    const vald = event.target.files?.[0] ?? null;

    event.target.value = '';

    if (vald !== null) {
        upload(vald);
    }
}

function upload(file) {
    error.value = null;

    router.post(`/containers/${props.container.ulid}/cover`, { file, account: account.value }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onSuccess: () => {
            close();
        },
        onError: (errors) => {
            error.value = errors.file ?? null;
        },
    });
}

function remove() {
    error.value = null;

    router.delete(`/containers/${props.container.ulid}/cover`, {
        preserveScroll: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onSuccess: () => {
            close();
        },
    });
}
</script>

<template>
    <slot name="trigger" :open="show" />

    <UiSheet :open="open" :heading="t('container.cover.heading')" :trigger="trigger" @close="close">
        <!--
            De två väljarna. `capture="environment"` är hela skillnaden mellan
            raderna: telefonen öppnar kameran för den ena och bildbiblioteket
            för den andra. Båda är `hidden` — knappen nedan är målet.
        -->
        <input
            :id="cameraId"
            ref="cameraInput"
            type="file"
            accept="image/*"
            capture="environment"
            class="hidden"
            @change="onSelected"
        >

        <input
            :id="deviceId"
            ref="deviceInput"
            type="file"
            accept="image/*"
            class="hidden"
            @change="onSelected"
        >

        <button
            type="button"
            class="inline-flex min-h-11 items-center gap-3 rounded-control px-2 text-left text-ink hover:bg-surface-muted"
            :disabled="pending"
            @click="chooseCamera"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-5 w-5 shrink-0"
                aria-hidden="true"
            >
                <path d="M4 8h3l1.5-2h7L17 8h3v11H4z"></path>
                <circle cx="12" cy="13" r="3.5"></circle>
            </svg>

            {{ t('container.cover.camera') }}
        </button>

        <button
            type="button"
            class="inline-flex min-h-11 items-center gap-3 rounded-control px-2 text-left text-ink hover:bg-surface-muted"
            :disabled="pending"
            @click="chooseDevice"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-5 w-5 shrink-0"
                aria-hidden="true"
            >
                <path d="M4 6h16v12H4z"></path>
                <path d="m4 16 5-5 4 4 3-3 4 4"></path>
            </svg>

            {{ t('container.cover.device') }}
        </button>

        <button
            type="button"
            class="inline-flex min-h-11 items-center gap-3 rounded-control px-2 text-left text-danger hover:bg-surface-muted"
            :disabled="pending"
            @click="remove"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-5 w-5 shrink-0"
                aria-hidden="true"
            >
                <path d="M5 7h14"></path>
                <path d="M10 7V5h4v2"></path>
                <path d="M7 7l1 13h8l1-13"></path>
            </svg>

            {{ t('container.cover.remove') }}
        </button>

        <!--
            Serverns mening, färdigöversatt: kvoten, storlekstaket eller en
            fil som inte är en bild. `role="alert"` och inte ett id ett fält
            pekar på: felet hör till arket och inte till ett fält i det —
            filväljarna är dolda, och raden ovan är den yta felet gäller
            (GenomgangTest § Beslut 6).
        -->
        <p v-if="error" role="alert" class="mt-1 text-sm text-danger">{{ error }}</p>
    </UiSheet>
</template>

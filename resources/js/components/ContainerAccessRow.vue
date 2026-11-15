<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AccessLevelField from './AccessLevelField.vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { accessScopeLabel, granteeLabel } from './accessPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En personrad i delningssidans enda lista, se issue 1250.
 *
 * **Ett val sparas direkt, text sparas med *Save*** — Tonys regel från
 * Containers/Edit.vue (issue 1245). Raden har därför ingen *Save*: nivån
 * skickas i sin egen PATCH så fort den ändras, och gästens utgångsdatum i sin
 * egen. `preserveState` håller sidan kvar, så statusraden står kvar på raden
 * och listan inte hoppar.
 *
 * **Egen komponent och inte en v-for i Sharing.vue.** Varje rad har sin egen
 * skrivning och sitt eget vänteläge — annars låser ett nivåval varje annan
 * rad på sidan, och ett fältfel på en rad hade blivit ett fel på alla.
 * Samma konstruktion och samma skäl som 53c:s AccountSettingsForm.
 *
 * **Mottagaren visas med namn, aldrig med sin ULID.** `ContainerAccessResource`
 * bär ULID:er — `/api` har inte bett om namn — och uppslaget kommer som egen
 * prop ur kontrollern. Formuleringen bor i
 * resources/js/components/accessPresentation.js, som historiklistan i
 * Sharing.vue anropar på samma sätt.
 *
 * **`kind` går inte att ändra.** Den är `prohibited` i
 * UpdateContainerAccessRequest, och att byta form på en relation är att
 * avsluta den och börja en ny. Inte heller `item` eller mottagaren går att
 * ändra.
 *
 * **Omfånget och `reach` är hela poängen med raden**: en rad med `item` och
 * `reach: 4` ska läsas som "motorn — och tre saker till". Talet kommer
 * färdigt ur resursen och räknas inte om här; se
 * resources/js/components/accessPresentation.js.
 *
 * **Utgången är ett smalt fält.** Det renderas bara på en rad som REDAN har
 * ett `expires_at` — i praktiken en `guest`. Att ge en permanent relation ett
 * slutdatum är ett annat beslut, och ingen har bett om det. `min` är i morgon
 * och inte i dag, för `after:now` tolkar dagens datum som midnatt bakåt och
 * avvisar det. Fältet skickas ENSAMT i sin PATCH: `sometimes` i den delade
 * FormRequesten lämnar då nivån orörd, och tvärtom.
 *
 * **Ingen väg att tömma utgången.** Nyckeln skickas bara när raden har ett
 * datum, alltså aldrig som `null` — den delade UpdateContainerAccessRequest
 * tillåter det, men webbytan erbjuder det inte: en `guest` utan utgång
 * motsäger § Beslut 5, och vägen från gäst till permanent går genom `kind`,
 * som är `prohibited` med flit. Raden under fältet säger det, i stället för
 * att ägaren ska leta efter en knapp som inte finns.
 *
 * **Borttagningen går genom UiConfirmDialog.** Dialogen är appens egen fråga
 * (issue 330), och fokusfällan är webbläsarens `<dialog>`; `trigger` lämnar
 * tillbaka fokus till knappen som öppnade den. `pending` håller dialogen kvar
 * medan DELETE-anropet är i luften, så den inte försvinner mitt i en
 * radering. Ingen egen Inertia-länk med metod delete och ingen
 * webbläsardialog: frågan ska se ut som resten av produkten.
 *
 * **Ingen knapp döljs eller stängs av efter behörighet.** Behörighetskontroller
 * görs i policies, aldrig genom att gömma en knapp ([[M10 Webbfrontend]]),
 * och ett fryst ägarkonto som får 403 på PATCH får den förklaringen i en rad
 * på sidan i stället — se `sharing.frozen` i Sharing.vue. `:disabled` här
 * betyder "ett anrop pågår", inte "du får inte".
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    access: { type: Object, required: true },
    itemNames: { type: Object, required: true },
    granteeNames: { type: Object, required: true },
    levels: { type: Array, required: true },
});

const { t } = useTranslations();

/* Fältets id måste vara unikt på sidan — varje rad har samma fältnamn. */
const field = (name) => `access-${props.access.ulid}-${name}`;

/* En gästrad, och bara en gästrad, har en utgång att flytta. */
const hasExpiry = props.access.expires_at !== null;

/* `type="date"` vill ha `YYYY-MM-DD` i lokal tid, inte en ISO 8601-sträng. */
function dateInput(value) {
    const date = value instanceof Date ? value : new Date(value);
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const tomorrow = (() => {
    const date = new Date();
    date.setDate(date.getDate() + 1);

    return dateInput(date);
})();

const url = `/containers/${props.containerUlid}/accesses/${props.access.ulid}`;

/*
 * Radens visade värden. Serverns svar skriver över dem genom propen — Inertia
 * behåller komponenten mellan två anrop (`preserveState`), och en lokal
 * kopia som inte nollställs hade då kunnat visa ett värde servern nekat.
 * Därför sätts den tillbaka uttryckligen vid fel.
 */
const level = ref(props.access.level);
const expiresAt = ref(hasExpiry ? dateInput(props.access.expires_at) : null);

/* Ett anrop pågår: väljaren, datumfältet och borttagningsknappen är stängda. */
const pending = ref(false);
const levelError = ref(null);
const expiryError = ref(null);
const status = ref(null);

/* Bekräftelsedialogen och elementet fokus går tillbaka till. */
const confirming = ref(false);
const removeTrigger = ref(null);
const revoking = ref(false);

/*
 * Nivån, sparad direkt. Kroppen bär bara `level`: `sometimes` i den delade
 * FormRequesten lämnar `expires_at` orörd, så ett nivåval flyttar aldrig ett
 * gästkontos utgång.
 */
function saveLevel(value) {
    if (value === props.access.level) {
        return;
    }

    const previous = level.value;

    level.value = value;
    levelError.value = null;
    status.value = null;

    router.patch(url, { level: value }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onSuccess: () => {
            status.value = 'updated';
        },
        onError: (errors) => {
            level.value = previous;
            levelError.value = errors.level ?? errors.expires_at ?? null;
        },
    });
}

/* Utgången, sparad direkt och i sin egen PATCH av samma skäl som nivån. */
function saveExpiry() {
    const saved = hasExpiry ? dateInput(props.access.expires_at) : null;

    if (expiresAt.value === null || expiresAt.value === saved) {
        return;
    }

    expiryError.value = null;
    status.value = null;

    router.patch(url, { expires_at: expiresAt.value }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            pending.value = true;
        },
        onFinish: () => {
            pending.value = false;
        },
        onSuccess: () => {
            status.value = 'updated';
        },
        onError: (errors) => {
            expiresAt.value = saved;
            expiryError.value = errors.expires_at ?? errors.level ?? null;
        },
    });
}

function openRemove(element) {
    removeTrigger.value = element;
    confirming.value = true;
}

function remove() {
    router.delete(url, {
        preserveScroll: true,
        onStart: () => {
            revoking.value = true;
        },
        onFinish: () => {
            revoking.value = false;
            confirming.value = false;
        },
    });
}
</script>

<template>
    <li class="flex flex-col gap-3 rounded-card border border-border bg-surface p-4">
        <!-- Namnet är radens titel och står först; omfånget under det. En
             person med två åtkomster får två rader med samma namn, och det
             är meningen — det är två olika omfång, inte två personer. -->
        <div class="flex flex-col gap-1">
            <span class="text-body font-medium text-ink">{{ granteeLabel(t, granteeNames, access) }}</span>
            <span class="text-meta text-ink-muted">{{ accessScopeLabel(t, itemNames, access) }}</span>
        </div>

        <AccessLevelField
            :id="field('level')"
            :model-value="level"
            :levels="levels"
            :error="levelError"
            :disabled="pending"
            @update:model-value="saveLevel"
        />

        <div v-if="hasExpiry" class="flex flex-col gap-1">
            <label :for="field('expires_at')" class="text-meta font-medium text-ink">
                {{ t('sharing.accesses.expires_at') }}
            </label>

            <input
                :id="field('expires_at')"
                v-model="expiresAt"
                :disabled="pending"
                :aria-describedby="expiryError ? `${field('expires_at')}-error` : undefined"
                type="date"
                :min="tomorrow"
                class="min-h-11 self-start rounded-control border border-border bg-surface px-2 text-body text-ink disabled:opacity-50"
                @change="saveExpiry"
            >

            <p class="text-meta text-ink-muted">{{ t('sharing.accesses.expires_fixed') }}</p>

            <p
                v-if="expiryError"
                :id="`${field('expires_at')}-error`"
                role="alert"
                class="text-body text-danger"
            >
                {{ expiryError }}
            </p>
        </div>

        <!-- Statusraden: valet sparades, och här står vad som hände. Beskedet
             bär `role="status"`; fältfelen bär `role="alert"` där de hör. -->
        <p v-if="status" role="status" class="text-meta text-ink-subtle">
            {{ t('sharing.accesses.updated') }}
        </p>

        <button
            type="button"
            :disabled="revoking"
            class="inline-flex min-h-11 items-center self-start rounded-control border border-danger px-3 text-body font-medium text-danger hover:bg-danger-soft disabled:opacity-50"
            @click="openRemove($event.currentTarget)"
        >
            {{ t('sharing.accesses.remove') }}
        </button>

        <UiConfirmDialog
            :open="confirming"
            :heading="t('sharing.accesses.remove_heading')"
            :body="t('sharing.accesses.remove_body')"
            :confirm-label="t('sharing.accesses.remove')"
            tone="danger"
            :pending="revoking"
            :trigger="removeTrigger"
            @confirm="remove"
            @cancel="confirming = false"
        />
    </li>
</template>

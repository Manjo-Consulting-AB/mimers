<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AccessLevelField from './AccessLevelField.vue';
import { accessKindLabel, accessScopeLabel, formatDate, grantedByLabel, granteeLabel } from './accessPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En giltig åtkomstrad i förvaltningsvyn, se issue 55a § Beslut 5, 6 och 9.
 *
 * Egen komponent och inte en v-for i Sharing.vue: varje rad har sitt EGET
 * formulär och sin EGEN PATCH — annars färgar ett valideringsfel på en rad
 * alla andra röda, och ett `form.processing` hade låst varje spara-knapp på
 * sidan. Samma konstruktion och samma skäl som 53c:s AccountSettingsForm.
 *
 * **Mottagaren och beviljaren visas med namn, aldrig med sin ULID.**
 * `ContainerAccessResource` bär ULID:er — `/api` har inte bett om namn — och
 * uppslagen kommer som egna propar ur kontrollern. Formuleringen bor i
 * resources/js/components/accessPresentation.js, som historiklistan i
 * Sharing.vue anropar på samma sätt.
 *
 * **`kind` går inte att ändra.** Den är `prohibited` i
 * UpdateContainerAccessRequest, och att byta form på en relation är att
 * avsluta den och börja en ny — därför visas den som en mening och aldrig som
 * en väljare (Beslut 5). Inte heller `item` eller mottagaren går att ändra.
 *
 * **Omfånget och `reach` är hela poängen med raden** (Beslut 6): en rad med
 * `item` och `reach: 4` ska läsas som "motorn — och tre saker till". Talet
 * kommer färdigt ur resursen och räknas inte om här; se
 * resources/js/components/accessPresentation.js.
 *
 * **Utgången är ett smalt fält** (arkitektsvaret § 3). Det renderas bara på
 * en rad som REDAN har ett `expires_at` — i praktiken en `guest`. Att ge en
 * permanent relation ett slutdatum är ett annat beslut, och ingen har bett
 * om det. `min` är i morgon och inte i dag, för `after:now` tolkar dagens
 * datum som midnatt bakåt och avvisar det.
 *
 * **Ingen väg att tömma utgången.** Formuläret bär `expires_at` bara när
 * raden har ett, så nyckeln skickas aldrig som `null` — den delade
 * UpdateContainerAccessRequest tillåter det, men webbytan erbjuder det inte:
 * en `guest` utan utgång motsäger § Beslut 5, och vägen från gäst till
 * permanent går genom `kind`, som är `prohibited` med flit. Raden under
 * fältet säger det, i stället för att ägaren ska leta efter en knapp som
 * inte finns.
 *
 * **Återkalla är en <Link method="delete">**, inte ett eget formulär: rutten
 * är en DELETE och Inertia skickar CSRF-tokenet åt oss, samma mönster som
 * utloggningsknappen i AppLayout. Ingen knapp döljs eller stängs av här —
 * behörighetskontroller görs i policies, aldrig genom att gömma en knapp
 * ([[M10 Webbfrontend]]), och ett fryst ägarkonto som får 403 på PATCH får
 * den förklaringen i en rad på sidan i stället.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    access: { type: Object, required: true },
    itemNames: { type: Object, required: true },
    granteeNames: { type: Object, required: true },
    grantedByNames: { type: Object, required: true },
    levels: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

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

/*
 * `expires_at` finns i formuläret bara när raden har ett värde. Utan det
 * villkoret hade nyckeln gått med som `null` på varje medlemsrad, och
 * `nullable` i den delade FormRequesten hade tolkat det som en tömning.
 */
const form = useForm(
    hasExpiry
        ? { level: props.access.level, expires_at: dateInput(props.access.expires_at) }
        : { level: props.access.level },
);

function submit() {
    // `preserveScroll`: sidan visar en lista, och ett hopp till toppen efter
    // en sparad nivå tappar läsarens plats.
    form.patch(`/containers/${props.containerUlid}/accesses/${props.access.ulid}`, { preserveScroll: true });
}
</script>

<template>
    <li class="flex flex-col gap-3 rounded border border-slate-300 bg-white p-4">
        <p class="text-sm text-slate-800">{{ accessKindLabel(t, access) }}</p>

        <dl class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-600">
            <div class="flex gap-1">
                <dt>{{ t('sharing.accesses.grantee') }}</dt>
                <dd>{{ granteeLabel(t, granteeNames, access) }}</dd>
            </div>
            <div class="flex gap-1">
                <dt>{{ t('sharing.accesses.granted_by') }}</dt>
                <dd>{{ grantedByLabel(t, grantedByNames, access) }}</dd>
            </div>
        </dl>

        <p v-if="access.expires_at" class="text-xs text-slate-600">
            {{ t('sharing.accesses.expires', { date: formatDate(access.expires_at, page.props.locale) }) }}
        </p>

        <p class="text-sm font-medium text-slate-800">{{ accessScopeLabel(t, itemNames, access) }}</p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <AccessLevelField
                v-model="form.level"
                :id="field('level')"
                :levels="levels"
                :error="form.errors.level"
            />

            <div v-if="hasExpiry" class="flex flex-col gap-1">
                <label :for="field('expires_at')" class="text-sm font-medium text-slate-800">
                    {{ t('sharing.accesses.expires_at') }}
                </label>

                <input
                    :id="field('expires_at')"
                    v-model="form.expires_at"
                    :aria-describedby="form.errors.expires_at ? `${field('expires_at')}-error` : undefined"
                    type="date"
                    :min="tomorrow"
                    class="self-start rounded border border-slate-300 px-2 py-1"
                >

                <p class="text-xs text-slate-600">{{ t('sharing.accesses.expires_fixed') }}</p>

                <p
                    v-if="form.errors.expires_at"
                    :id="`${field('expires_at')}-error`"
                    role="alert"
                    class="text-sm text-red-700"
                >
                    {{ form.errors.expires_at }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center self-start rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('sharing.accesses.save') }}
            </button>
        </form>

        <Link
            :href="`/containers/${containerUlid}/accesses/${access.ulid}`"
            method="delete"
            as="button"
            preserve-scroll
            class="inline-flex min-h-11 items-center self-start text-sm text-red-700 underline"
        >
            {{ t('sharing.accesses.revoke') }}
        </Link>
    </li>
</template>

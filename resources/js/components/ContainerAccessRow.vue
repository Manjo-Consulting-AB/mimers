<script setup>
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AccessLevelField from './AccessLevelField.vue';
import { accessKindLabel, accessScopeLabel, formatDate } from './accessPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En giltig åtkomstrad i förvaltningsvyn, se issue 55a § Beslut 5, 6 och 9.
 *
 * Egen komponent och inte en v-for i Sharing.vue: varje rad har sitt EGET
 * formulär och sin EGEN PATCH — annars färgar ett valideringsfel på en rad
 * alla andra röda, och ett `form.processing` hade låst varje spara-knapp på
 * sidan. Samma konstruktion och samma skäl som 53c:s AccountSettingsForm.
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
    levels: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

/* Fältets id måste vara unikt på sidan — varje rad har samma fältnamn. */
const field = (name) => `access-${props.access.ulid}-${name}`;

const form = useForm({
    level: props.access.level,
});

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
                <dd class="font-mono">{{ access.grantee }}</dd>
            </div>
            <div class="flex gap-1">
                <dt>{{ t('sharing.accesses.granted_by') }}</dt>
                <dd class="font-mono">{{ access.granted_by }}</dd>
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

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
            >
                {{ t('sharing.accesses.save') }}
            </button>
        </form>

        <Link
            :href="`/containers/${containerUlid}/accesses/${access.ulid}`"
            method="delete"
            as="button"
            preserve-scroll
            class="self-start text-sm text-red-700 underline"
        >
            {{ t('sharing.accesses.revoke') }}
        </Link>
    </li>
</template>

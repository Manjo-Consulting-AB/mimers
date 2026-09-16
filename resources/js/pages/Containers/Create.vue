<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Skapa en pärm, se issue 54 § Beslut 5 och 8.
 *
 * TRE fält: `name`, `kind` och `account`. Ägarkontot måste väljas, för
 * servern har inget begrepp "aktivt konto" (issue 8 § Beslut 8) — det är
 * därför `StoreContainerRequest` kräver `account` som konto-ULID.
 *
 * Kontolistan kommer ur den DELADE propen `auth.accounts` och inte ur en
 * egen sidprop (Beslut 5): en fråga för samma lista är en fråga för mycket.
 *
 * Är användaren med i EXAKT ett konto är det förvalt och visas som en läsbar
 * rad i stället för en väljare — ett val mellan ett alternativ är ingen
 * fråga. Är hon med i flera är det en väljare UTAN förval: servern vet inte
 * vilket konto hon menar, och ett påhittat förval hade blivit fel hälften av
 * gångerna. Väljaren listar alla hennes konton — även ett fryst; är kontot
 * `read_only` svarar policyn 403, och vyn ska inte gissa sig förbi det.
 *
 * Typ-listan kommer som prop ur `Container::KINDS` (Beslut 8), samma teknik
 * som 53c:s tidszonslista. Ingen egen lista i JavaScript: två listor blir två
 * sanningar. `kind` är presentation och bara presentation — ingenting i den
 * här vyn grenar på värdet.
 *
 * `errors.quota` renderas som en ruta OVANFÖR formuläret, inte under ett
 * fält (Beslut 4): ett kvotfel handlar inte om vad användaren skrev. Servern
 * lägger meningen där när containertaket slår i.
 */
const props = defineProps({
    kinds: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();
const { focusFirstError } = useErrorFocus();

const accounts = computed(() => page.props.auth?.accounts ?? []);

const singleAccount = computed(() => (accounts.value.length === 1 ? accounts.value[0] : null));

const form = useForm({
    name: '',
    kind: props.kinds[0] ?? 'other',
    account: singleAccount.value?.ulid ?? '',
});

function submit() {
    form.post('/containers', { onError: focusFirstError });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('container.create.title')" />

        <h1 class="text-2xl font-semibold">{{ t('container.create.heading') }}</h1>

        <p
            v-if="form.errors.quota"
            id="quota-error"
            role="alert"
            tabindex="-1"
            class="mt-6 max-w-lg rounded border border-red-300 bg-red-50 px-4 py-3 text-red-800 outline-none"
        >
            {{ form.errors.quota }}
        </p>

        <form class="mt-8 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('container.create.name')"
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
                :label="t('container.create.kind')"
                id="kind"
                :error="form.errors.kind"
            >
                <select
                    id="kind"
                    v-model="form.kind"
                    :aria-describedby="describedBy"
                    name="kind"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option v-for="kind in kinds" :key="kind" :value="kind">
                        {{ t(`container.kind.${kind}`) }}
                    </option>
                </select>
            </FormField>

            <!-- Ett enda konto: värdet är förvalt och visas som text. Ingen
                 gömd väljare — det finns ingenting att välja. -->
            <div v-if="singleAccount" class="flex flex-col gap-1">
                <p class="text-sm font-medium text-slate-800">{{ t('container.create.account') }}</p>
                <p>{{ singleAccount.name }}</p>
            </div>

            <FormField
                v-else
                v-slot="{ describedBy }"
                :label="t('container.create.account')"
                id="account"
                :error="form.errors.account"
            >
                <select
                    id="account"
                    v-model="form.account"
                    :aria-describedby="describedBy"
                    name="account"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option value="" disabled>{{ t('container.create.account_choose') }}</option>
                    <option v-for="account in accounts" :key="account.ulid" :value="account.ulid">
                        {{ account.name }}
                    </option>
                </select>
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center self-start rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('container.create.submit') }}
            </button>
        </form>
    </AppLayout>
</template>

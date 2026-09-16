<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Ett inkommande ägarbyte, se issue 67b § Beslut 5, 6 och 7.
 *
 * **Konsekvenserna står FÖRE knappen** (Beslut 6): vilken pärm, från vilket
 * konto, hur många items som följer med och hur många som undantas, vilken
 * åtkomst avsändaren behåller, och att mottagaren får tolv månader Pro.
 * Därför fem rader text och två knappar under dem, i den ordningen — att
 * trycka först och läsa sedan är fel ordning för den mest konsekvensrika
 * handlingen i produkten.
 *
 * **`item_count` och `excluded_count` kommer färdiga från servern** och
 * räknas aldrig om här: `item_count` är pärmens levande items, räknade i EN
 * fråga för hela inkorgen (OwnershipTransferController::itemCounts()), och
 * `excluded_count` är längden på radens `excluded_items`.
 *
 * **Kontot väljs bara när det finns ett val.** Är raden ställd till ett konto
 * (`to_account` satt) är mottagaren bestämd av avsändaren, och
 * AcceptOwnershipTransferRequest nekar en kropp som pekar på ett annat konto.
 * Är den ställd till en adress MÅSTE kroppen bära `to_account`: den inloggade
 * kan vara medlem i flera konton, och systemet får inte gissa vilket av dem
 * som köpte båten. Valet ligger därför i kontots väljare — och medlemskapet
 * prövas av requesten, inte här.
 *
 * **Båda besluten är slutgiltiga** (Beslut 7). Bekräftelserutan säger det, och
 * meningen under knapparna säger det igen: det finns ingen väg tillbaka som
 * knapp, och en ny överlåtelse måste skickas av avsändaren.
 *
 * Kontolistan kommer ur den delade propen `auth.accounts` och inte ur en egen
 * sidprop — samma väg som ItemForm, OpenOccurrence och Containers/Create
 * använder. En fråga för samma lista är en fråga för mycket.
 */
const props = defineProps({
    /* Raden ur inkorgen: OwnershipTransferResource plus `from_account_name`,
       `item_count` och `excluded_count` — se kontrollern. */
    transfer: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const accounts = computed(() => page.props.auth?.accounts ?? []);

/* Kontot måste pekas ut när raden nåddes på adress och inte på konto. */
const choosesAccount = computed(() => props.transfer.to_account === null);

const accountId = computed(() => `transfer-account-${props.transfer.ulid}`);

const form = useForm({
    to_account: choosesAccount.value ? (accounts.value[0]?.ulid ?? '') : '',
});

const excluded = computed(() => props.transfer.excluded_count);
const following = computed(() => props.transfer.item_count - excluded.value);

const retained = computed(() => props.transfer.retain_access_level === null
    ? t('transfer.card.retain_none')
    : t('transfer.card.retain', { level: t(`sharing.level.${props.transfer.retain_access_level}.label`) }));

function accept() {
    if (! window.confirm(t('transfer.card.accept_confirm'))) {
        return;
    }

    form
        .transform((data) => ({ ...data, to_account: data.to_account === '' ? null : data.to_account }))
        .post(`/transfers/${props.transfer.ulid}/accept`);
}

function reject() {
    if (! window.confirm(t('transfer.card.reject_confirm'))) {
        return;
    }

    form.post(`/transfers/${props.transfer.ulid}/reject`);
}
</script>

<template>
    <li class="flex flex-col gap-2 rounded border border-slate-300 bg-white p-4 text-sm">
        <p class="font-medium text-slate-800">
            {{ t('transfer.card.container', { container: transfer.container.name }) }}
        </p>
        <p class="text-slate-700">{{ t('transfer.card.from', { account: transfer.from_account_name }) }}</p>

        <!--
            Antalen står som en mening och inte som tre tal: ":following av
            :total följer med, :excluded undantas" är vad som händer, medan
            "5 / 8 / 3" är tre siffror utan ämne.
        -->
        <p class="text-slate-700">
            {{ t('transfer.card.items', { following, total: transfer.item_count, excluded }) }}
        </p>
        <p class="text-slate-700">{{ retained }}</p>

        <p class="text-slate-700">{{ t('transfer.card.pro') }}</p>
        <p class="text-slate-600">{{ t('transfer.card.quota') }}</p>

        <div v-if="choosesAccount && accounts.length > 1" class="mt-1 flex flex-col gap-1">
            <label :for="accountId" class="text-sm font-medium text-slate-800">
                {{ t('transfer.card.account') }}
            </label>

            <select
                :id="accountId"
                v-model="form.to_account"
                class="self-start rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option v-for="option in accounts" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </div>

        <!--
            Medlemskapet prövas av den delade AcceptOwnershipTransferRequest —
            ett konto användaren inte är med i avvisas på servern, och felet
            hamnar här. Ingen klientregel upprepar den kontrollen.
        -->
        <p v-if="form.errors.to_account" class="text-sm text-red-700 outline-none">
            {{ form.errors.to_account }}
        </p>

        <p class="mt-1 text-xs text-slate-600">{{ t('transfer.card.final') }}</p>

        <div class="mt-1 flex flex-wrap gap-3">
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                @click="accept"
            >
                {{ form.processing ? t('common.pending.transfer') : t('transfer.accept') }}
            </button>

            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                @click="reject"
            >
                {{ form.processing ? t('common.pending.default') : t('transfer.reject') }}
            </button>
        </div>
    </li>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret som initierar ett ägarbyte, se issue 67b § Beslut 2.
 *
 * **Fyra saker väljs, och inget femte:** mottagaren, vilka items som undantas,
 * vilken åtkomst avsändaren behåller — och ingenting mer. Nivåerna kommer som
 * prop ur kontrollern, som i sin tur läser dem ur den delade
 * StoreOwnershipTransferRequest; vyn uppfinner ingen nivå requesten avvisar.
 *
 * **Mottagaren har två vägar och exakt en ska fyllas i.** `to_account` är ett
 * konto-ULID och `to_email` en adress, och `prohibits` i den delade requesten
 * nekar båda samtidigt. Vyn upprepar inte den regeln: den skickar `null` för
 * ett tomt fält (`transform()` nedan — en tom sträng är varken `null` eller en
 * ULID och fastnar i `ulid`-regeln), och servern svarar med fältfel på båda
 * när ingen eller båda är ifyllda. Samma mönster som InvitationForm:s `item`.
 *
 * **Undantagen är undantag, inte ett urval.** Kryssrutorna listar pärmens
 * items, men det som skickas är de markerade — allt annat följer med, och
 * raden ovanför säger det med antalet utskrivet, så en avsändare som kryssar
 * fel ser vad som faktiskt händer. Det är hela skillnaden mot en "välj vad som
 * ska ingå"-lista, och skälet att ett varv vågar använda funktionen.
 *
 * **Egen komponent och inte ett inline-formulär i Transfers.vue.** Formuläret
 * äger sitt eget tillstånd, sin egen postning och sina egna fel, precis som
 * InvitationForm gör på delningssidan: annars färgar ett fältfel på
 * mottagaren varje rad i listan röd. Domänfelet (`transfer`) hör däremot till
 * sidan och ritas där — det gäller pärmens tillstånd och inte ett fält.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },

    /* Pärmens levande items, ur kontrollern — `{ulid, name}`. */
    items: { type: Array, required: true },

    /* De nivåer requesten tillåter, ur kontrollern. */
    retainLevels: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    to_account: '',
    to_email: '',
    excluded_items: [],
    retain_access_level: '',
});

/* Antalet som följer med, räknat på det användaren just nu har kryssat. */
const following = computed(() => props.items.length - form.excluded_items.length);

function toggleItem(ulid, checked) {
    form.excluded_items = checked
        ? [...form.excluded_items, ulid]
        : form.excluded_items.filter((value) => value !== ulid);
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            to_account: data.to_account === '' ? null : data.to_account,
            to_email: data.to_email === '' ? null : data.to_email,
            retain_access_level: data.retain_access_level === '' ? null : data.retain_access_level,
        }))
        .post(`/containers/${props.containerUlid}/transfer`, {
            // Formuläret står ovanför en lista, och ett hopp till toppen efter
            // en skickad överlåtelse tappar läsarens plats.
            preserveScroll: true,
            onError: focusFirstError,
            // Mottagaren töms när överlåtelsen gått igenom — nästa gäller en
            // annan person. Undantagen och nivån står kvar: de beskriver vad
            // avsändaren behåller, och det är samma sak nästa gång.
            onSuccess: () => form.reset('to_account', 'to_email'),
        });
}
</script>

<template>
    <form class="mt-6 flex flex-col gap-4 rounded border border-slate-300 bg-white p-4" @submit.prevent="submit">
        <h2 class="text-lg font-semibold">{{ t('transfer.form.heading') }}</h2>

        <!--
            Vad överlåtelsen omfattar, innan den skickas (Beslut 2). Fyra
            meningar i en: hela pärmen följer med utom undantagen,
            förbrukningen flyttar till mottagarens konto, åtkomsterna
            återkallas, och mottagaren får tolv månader Pro.
        -->
        <p class="text-sm text-slate-600">{{ t('transfer.form.notice') }}</p>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-sm font-medium text-slate-800">{{ t('transfer.form.recipient_heading') }}</legend>
            <p class="text-sm text-slate-600">{{ t('transfer.form.recipient_help') }}</p>

            <FormField
                v-slot="{ describedBy }"
                :label="t('transfer.form.account')"
                id="transfer-account"
                :error="form.errors.to_account"
            >
                <input
                    id="transfer-account"
                    v-model="form.to_account"
                    :aria-describedby="describedBy"
                    type="text"
                    name="to_account"
                    autocomplete="off"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('transfer.form.email')"
                id="transfer-email"
                :error="form.errors.to_email"
            >
                <input
                    id="transfer-email"
                    v-model="form.to_email"
                    :aria-describedby="describedBy"
                    type="email"
                    name="to_email"
                    autocomplete="off"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>
        </fieldset>

        <fieldset class="flex flex-col gap-3">
            <legend class="text-sm font-medium text-slate-800">{{ t('transfer.excluded.heading') }}</legend>
            <p class="text-sm text-slate-600">{{ t('transfer.excluded.help') }}</p>

            <!--
                Antalet utskrivet och inte bara en mening: "allt annat följer
                med" är en regel, men ":following av :total följer med" är vad
                som faktiskt händer när användaren kryssar.
            -->
            <p class="text-sm text-slate-700">
                {{ t('transfer.excluded.following', { following, total: items.length, excluded: form.excluded_items.length }) }}
            </p>

            <label v-for="item in items" :key="item.ulid" :for="`transfer-excluded-${item.ulid}`" class="flex min-h-11 cursor-pointer items-center gap-2">
                <input
                    :id="`transfer-excluded-${item.ulid}`"
                    type="checkbox"
                    class="mt-0.5"
                    :checked="form.excluded_items.includes(item.ulid)"
                    @change="toggleItem(item.ulid, $event.target.checked)"
                >
                <span class="text-sm">{{ item.name }}</span>
            </label>

            <p v-if="form.errors.excluded_items" tabindex="-1" class="text-sm text-red-700 outline-none">
                {{ form.errors.excluded_items }}
            </p>
        </fieldset>

        <div class="flex flex-col gap-1">
            <label for="transfer-retain" class="text-sm font-medium text-slate-800">
                {{ t('transfer.form.retain') }}
            </label>

            <!--
                Nivånamnen kommer ur `sharing.level.*` — samma ladder, samma
                ord. Att skriva av dem här hade gett två sanningar om vad
                "Läsa" och "Ändra" betyder.
            -->
            <select
                id="transfer-retain"
                v-model="form.retain_access_level"
                name="retain_access_level"
                class="self-start rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option value="">{{ t('transfer.retain.none') }}</option>
                <option v-for="level in retainLevels" :key="level" :value="level">
                    {{ t(`sharing.level.${level}.label`) }}
                </option>
            </select>

            <p v-if="form.errors.retain_access_level" class="text-sm text-red-700">
                {{ form.errors.retain_access_level }}
            </p>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-11 items-center self-start rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('transfer.form.submit') }}
        </button>
    </form>
</template>

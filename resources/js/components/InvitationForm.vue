<script setup>
import { useForm } from '@inertiajs/vue3';
import AccessLevelField from './AccessLevelField.vue';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Inbjudningsformuläret, se issue 55b § Beslut 5, 6 och 8.
 *
 * **Tre fält och inget fjärde.** `email` är en adress och ingenting mer: ingen
 * kontroll av om den redan har ett konto, och ingen ledtråd åt något håll —
 * svaret ska vara detsamma oavsett. Det är hela skälet till att webben bjuder
 * in i stället för att bevilja direkt (55a § Beslut 2). `level` är
 * AccessLevelField från 55a, oförändrad. `item` är omfånget: hela containern
 * (förvalt) eller ett enskilt item ur listan kontrollern skickar.
 *
 * **`item` skickas som `null` när hela containern valts.** Den delade
 * StoreInvitationRequest är `nullable` på fältet, och en tom sträng är
 * varken `null` eller en ULID — den fastnar i `Rule::exists` och blir ett
 * fältfel. `transform()` är därför inte en bekvämlighet utan det som gör
 * standardvalet till ett giltigt tomt omfång.
 *
 * **Egen komponent och inte ett inline-formulär i Sharing.vue.** Formuläret
 * äger sitt eget tillstånd, sin egen postning och sina egna fel, precis som
 * ContainerAccessRow gör för åtkomsterna: annars färgar ett fältfel på
 * inbjudan varje annan rad på sidan röd. Kvotfelet hör också hit — det kommer
 * ur samma postning, och rutan ska stå vid formuläret och inte vid listan.
 *
 * Ingen klientvalidering: reglerna bor i den delade FormRequesten, och ett
 * fält som avvisas här avvisas på samma sätt på `/api`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    levels: { type: Array, required: true },
    items: { type: Array, required: true },
});

const { t } = useTranslations();

const form = useForm({
    email: '',
    level: 'read',
    item: '',
});

function submit() {
    form
        .transform((data) => ({ ...data, item: data.item === '' ? null : data.item }))
        .post(`/containers/${props.containerUlid}/invitations`, {
            // Sidan är en lista, och ett hopp till toppen efter en skickad
            // inbjudan tappar läsarens plats.
            preserveScroll: true,
            // Adressen töms när den gått igenom — nästa inbjudan gäller en
            // annan person. Nivån och omfånget står kvar: att bjuda in flera
            // till samma omfång är det vanliga.
            onSuccess: () => form.reset('email'),
        });
}
</script>

<template>
    <form class="mt-4 flex flex-col gap-4 rounded-card border border-border bg-surface p-4" @submit.prevent="submit">
        <!--
            Kvotgränserna (§ Beslut 6): `quota.shared_users_exceeded` och
            `quota.pending_invitations_exceeded` kommer ur det delade lagret
            och översätts av servern till meningen här. Nyckeln är `quota` och
            inte ett fältnamn — felet handlar om kontots gräns och inte om vad
            användaren skrev, samma uppdelning som issue 54 § Beslut 4 satte.
        -->
        <p
            v-if="form.errors.quota"
            role="alert"
            class="rounded-control border border-warning bg-warning/10 px-3 py-2 text-body text-ink"
        >
            {{ form.errors.quota }}
        </p>

        <FormField v-slot="{ describedBy }" :label="t('sharing.invitations.email')" id="invitation-email" :error="form.errors.email">
            <UiInput
                id="invitation-email"
                v-model="form.email"
                :described-by="describedBy"
                type="email"
                name="email"
                autocomplete="off"
                required
            />
        </FormField>

        <AccessLevelField
            v-model="form.level"
            id="invitation-level"
            :levels="levels"
            :error="form.errors.level"
        />

        <FormField
            v-slot="{ describedBy }"
            :label="t('sharing.invitations.item')"
            id="invitation-item"
            :error="form.errors.item"
        >
            <UiSelect
                id="invitation-item"
                v-model="form.item"
                :described-by="describedBy"
                name="item"
                class="self-start"
            >
                <option value="">{{ t('sharing.invitations.item_container') }}</option>
                <option v-for="item in items" :key="item.ulid" :value="item.ulid">{{ item.name }}</option>
            </UiSelect>
        </FormField>

        <UiButton type="submit" :pending="form.processing" class="self-start">
            {{ form.processing ? t('common.pending.default') : t('sharing.invitations.submit') }}
        </UiButton>
    </form>
</template>

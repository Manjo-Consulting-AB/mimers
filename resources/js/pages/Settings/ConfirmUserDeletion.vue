<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import UserDeletionSummary from '../../components/UserDeletionSummary.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Bekräftelsesidan i mejlet, se [[M22 Redo för testare]] § 145 och
 * [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-09-28.
 *
 * **Sidan raderar ingenting.** Det är hela rättelsen av bugg #577: länken i
 * mejlet går hit, och först knappen postar till
 * `POST /settings/delete-user/{token}`, som raderar. En mejlskanner som
 * förhandshämtar länkar gör en GET, och en GET som raderade hade låtit en
 * skanner radera ett konto (beslut 2).
 *
 * **Sidan kräver ingen inloggning, och ritas därför i AppLayout.** En gäst
 * ska kunna genomföra raderingen — det är hela beslutet (beslut 1) — och
 * inställningsskalet hade visat navigation en gäst inte har. Den som är
 * inloggad som någon annan ser samma sida: tokenet avgör vem som raderas, och
 * hennes egen session rörs inte.
 *
 * **Tokenet ligger i props och postas i sökvägen.** Det är samma klartext som
 * stod i länken; servern har bara hashen, och sidan visar den ingenstans.
 *
 * **Listorna och spärrarna ritas av UserDeletionSummary**, samma komponent som
 * säkerhetssidan använder. Det som skiljer sidorna åt är ramen: här är det
 * RADERINGEN som inte kan genomföras, där är det BEGÄRAN som inte kan göras —
 * och därför fyller var sida sin egen mening i `#blocked`. Är en spärr i vägen
 * är knappen avstängd, och `submit()` vaktar också: en avstängd knapp hindrar
 * ett klick men inte Enter i ett fält.
 */
const props = defineProps({
    token: { type: String, required: true },
    deletion: { type: Object, required: true },
});

const { t } = useTranslations();

const form = useForm({});

const blocked = computed(() => props.deletion.blockers.length > 0);

function submit() {
    if (blocked.value) {
        return;
    }

    form.post(`/settings/delete-user/${props.token}`);
}
</script>

<template>
    <Head :title="t('settings.security.deletion_link.heading')" />

    <section class="mx-auto flex max-w-lg flex-col gap-4 py-12">
        <h1 class="text-2xl font-semibold">{{ t('settings.security.deletion_link.heading') }}</h1>

        <p class="text-sm text-slate-700">{{ t('settings.security.deletion_link.intro') }}</p>

        <UserDeletionSummary
            :accounts-to-delete="props.deletion.accountsToDelete"
            :accounts-to-leave="props.deletion.accountsToLeave"
            :blockers="props.deletion.blockers"
        >
            <template #blocked>
                <h3 class="font-medium text-amber-900">{{ t('settings.security.deletion.blocked_heading') }}</h3>
                <p class="text-sm text-amber-900">{{ t('settings.security.deletion_link.blocked') }}</p>
            </template>
        </UserDeletionSummary>

        <!-- Ingen felfältrad: formuläret har inget fält, och den här
             rutten prövar ingen engångskod — takgränsen och dess
             `email`-fel hör till BEGÄRAN, som har sitt eget formulär på
             säkerhetssidan. -->
        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <button
                type="submit"
                :disabled="blocked || form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-red-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ blocked
                    ? t('settings.security.deletion.blocked_button')
                    : (form.processing ? t('common.pending.default') : t('settings.security.deletion_link.submit')) }}
            </button>
        </form>
    </section>
</template>

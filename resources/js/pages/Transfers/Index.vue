<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import IncomingTransferCard from '../../components/IncomingTransferCard.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Mottagarens inkorg, se issue 67b § Beslut 1, 5 och 6.
 *
 * **Sidan ligger på TOPPNIVÅ, utanför pärmen.** Mottagaren har den inte ännu
 * — den är inte hennes att navigera i — så sidan ritas i AppLayout och inte i
 * ContainerLayout, och den har ingen `container`-prop. Det är samma skäl som
 * gör att rutten är `/transfers` och inte `/containers/{container}/transfers`.
 *
 * **Här hittar hon sin begäran på identitet, aldrig på en länk** (Beslut 5).
 * Ingen token finns i någon URL, ingen session bär något mellan två anrop, och
 * sidan frågar servern vilka rader som är hennes — konton hon är medlem i,
 * eller hennes verifierade adress. Det är hela skillnaden mot
 * Invitations/Show.vue, och den är avsiktlig: en inbjudan ger läsrätt till en
 * pärm, ett ägarbyte överlåter hela pärmen, och en bärartoken i ett mejl till
 * en overifierad adress vore en kapabilitet att ta emot någon annans pärm.
 * Mejlet (App\Notifications\OwnershipTransferNotification) pekar därför på
 * just den här sökvägen och ingenting annat.
 *
 * **Tomt är ett svar, inte ett fel.** Ingen inkommande överlåtelse är det
 * vanliga läget för nästan varje användare, och en tom lista säger det med en
 * mening i stället för med en rubrik utan innehåll.
 *
 * Domänfelet ur en accept eller ett avslag (`transfer`) ritas EN gång här och
 * inte per kort: felet gäller hela begäran — till exempel att mottagarkontots
 * plan inte rymmer pärmen — och en ruta inuti ett av flera kort hade pekat ut
 * fel kort.
 */
defineProps({
    transfers: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();
</script>

<template>
    <AppLayout>
        <Head :title="t('transfer.inbox.title')" />

        <h1 class="text-2xl font-semibold">{{ t('transfer.inbox.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('transfer.inbox.intro') }}</p>

        <!--
            Meningen formulerades på servern ur `lang/` av
            App\Support\Frontend\ApiErrorTranslator — ett kvotfel bär sin gräns
            och sitt värde (:limit och :used), och en rå felkod når aldrig
            skärmen (Beslut 6).
        -->
        <p
            v-if="page.props.errors.transfer"
            id="transfer-error"
            role="alert"
            tabindex="-1"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 outline-none"
        >
            {{ page.props.errors.transfer }}
        </p>

        <p v-if="transfers.length === 0" class="mt-6 text-sm text-slate-600">
            {{ t('transfer.inbox.empty') }}
        </p>

        <ul v-else class="mt-6 flex flex-col gap-4">
            <IncomingTransferCard
                v-for="transfer in transfers"
                :key="transfer.ulid"
                :transfer="transfer"
            />
        </ul>
    </AppLayout>
</template>

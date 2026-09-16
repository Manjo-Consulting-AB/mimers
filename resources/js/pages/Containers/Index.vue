<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Pärmlistan, se issue 54 § Beslut 6, 9 och 10.
 *
 * Sorteringen och urvalet kommer från servern — den här vyn filtrerar
 * ingenting. Det som avgörs HÄR är presentationen, och två saker härleds ur
 * de delade propsen i stället för ur ett eget serverfält (Beslut 10):
 *
 *   - Är pärmen delad med mig? `ContainerResource` bär ägarkontots ULID i
 *     `account`. Är den ULID:n inte ett av mina konton (`auth.accounts`) är
 *     pärmen någon annans. Ett `shared`-fält i `/api` som bara webben
 *     behöver är precis den drift [[ADR-0021 Frontendteknik]] § Konsekvenser
 *     varnar för.
 *   - Ägarkontots namn visas bara när användaren är med i MER än ett konto.
 *     Med ett enda konto är namnet brus.
 *
 * Den aktiva raden bär `aria-current` och en synlig etikett, och har ingen
 * knapp — den är redan vald (Beslut 6). Den aktiva pärmen läses ur den
 * delade propen `activeContainer`, som bär ULID:t och ingenting annat.
 *
 * Redigeringslänken visas efter `can.update`, som kontrollern räknat med
 * policyn (Beslut 9). Flaggan är presentation; rutten auktoriserar ändå.
 *
 * Pärmnamnet är en länk till pärmens EGEN sida — itemlistan, se issue 57a
 * § Beslut 1. Det är den enda ändringen i den här filen; knapparna för aktiv
 * pärm och redigering står kvar som de är.
 */
defineProps({
    containers: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

const accounts = computed(() => page.props.auth?.accounts ?? []);
const activeUlid = computed(() => page.props.activeContainer ?? null);

const accountByUlid = computed(() => Object.fromEntries(accounts.value.map((account) => [account.ulid, account])));

const showsAccountName = computed(() => accounts.value.length > 1);

const accountName = (container) => accountByUlid.value[container.account]?.name ?? null;

const isShared = (container) => accountName(container) === null;
</script>

<template>
    <AppLayout>
        <Head :title="t('container.index.title')" />

        <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold">{{ t('container.index.heading') }}</h1>

            <Link href="/containers/create" class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 text-sm font-medium text-white">
                {{ t('container.index.create') }}
            </Link>
        </div>

        <p v-if="containers.length === 0" class="mt-8 text-slate-700">
            {{ t('container.index.empty') }}
        </p>

        <ul v-else class="mt-8 flex flex-col divide-y divide-slate-200">
            <li v-for="container in containers" :key="container.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-4">
                <Link
                    :href="`/containers/${container.ulid}`"
                    class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                >
                    {{ container.name }}
                </Link>

                <span class="text-sm text-slate-600">{{ t(`container.kind.${container.kind}`) }}</span>

                <span v-if="showsAccountName && !isShared(container)" class="text-sm text-slate-600">
                    {{ accountName(container) }}
                </span>

                <span v-if="isShared(container)" class="text-sm text-slate-600">
                    {{ t('container.index.shared') }}
                </span>

                <span
                    v-if="container.ulid === activeUlid"
                    aria-current="true"
                    class="rounded bg-slate-200 px-2 py-1 text-sm font-medium"
                >
                    {{ t('container.index.active') }}
                </span>

                <Link
                    v-else
                    method="put"
                    :href="`/containers/${container.ulid}/active`"
                    class="inline-flex min-h-11 items-center text-sm text-blue-700 hover:underline"
                >
                    {{ t('container.index.make_active') }}
                </Link>

                <Link
                    v-if="container.can.update"
                    :href="`/containers/${container.ulid}/edit`"
                    class="inline-flex min-h-11 items-center text-sm text-blue-700 hover:underline"
                >
                    {{ t('container.index.edit') }}
                </Link>
            </li>
        </ul>

        <!--
            Vägen tillbaka, se issue 62b § Beslut 8. Raden ligger under listan
            och är ALLTID synlig — också för en tom lista, för den som raderat
            sin enda pärm är den som mest behöver den. Ingen räknare: ett tal
            hade varit en fråga per sidladdning, och texten är konstant.
        -->
        <p class="mt-8 text-sm">
            <Link href="/trash/containers" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                {{ t('trash.containers.link') }}
            </Link>
        </p>
    </AppLayout>
</template>

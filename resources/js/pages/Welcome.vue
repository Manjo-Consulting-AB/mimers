<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

/*
 * Startsidan, se issue 51 § Beslut 11. Komponentnamnet `Welcome` och rutten
 * `/` ligger fast — tests/Feature/SkeletonTest.php assertar båda — men
 * innehållet är bytt från skalets "Skalet står" till en väg in i produkten.
 *
 * Sidan är publik och renderas för både en gäst och en inloggad användare;
 * den byter bara mål. Att skicka en inloggad vidare från /login är
 * `guest`-middlewarens jobb, inte den här sidans.
 */
const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <AppLayout>
        <Head title="Mimers" />

        <div class="py-12 text-center">
            <h1 class="text-3xl font-semibold">Mimers</h1>
            <p class="mt-3 text-slate-600">Pärmen för båten, husvagnen, huset och bilen.</p>

            <Link
                v-if="user"
                href="/dashboard"
                class="mt-8 inline-block rounded bg-blue-700 px-4 py-2 font-medium text-white"
            >
                Till översikten
            </Link>
            <Link
                v-else
                href="/login"
                class="mt-8 inline-block rounded bg-blue-700 px-4 py-2 font-medium text-white"
            >
                Logga in
            </Link>
        </div>
    </AppLayout>
</template>

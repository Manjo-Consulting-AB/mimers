<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessage from '../components/FlashMessage.vue';

/*
 * Den enda layouten i M10.
 *
 * Varje sida under resources/js/pages/ wrappar sitt innehåll i den här
 * komponenten — <AppLayout> ... </AppLayout> — och lägger ingenting eget i
 * navigeringen. Femton issues renderar sina vyer här; uppfinner en av dem
 * en egen header har den byggt den sextonde.
 *
 * Layouten läser de delade propsen (auth och flash) och skickar ingenting
 * vidare nedåt — en sida som behöver användaren läser usePage().props själv.
 * Den håller inget eget tillstånd.
 *
 * URL:er skrivs som strängar i <Link href="/dashboard">. Ingen
 * routinghjälpare i JavaScript, se issue 51 § Beslut 7: en URL som bara
 * servern kan bygga — en signerad länk, en med ett ULID i — skickas som en
 * prop i stället.
 */
const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <div class="flex min-h-full flex-col bg-slate-50 text-slate-900">
        <header class="border-b border-slate-200 bg-white">
            <nav class="mx-auto flex w-full max-w-3xl items-center justify-between gap-4 px-4 py-3">
                <Link href="/" class="text-lg font-semibold">Mimers</Link>

                <div class="flex items-center gap-4 text-sm">
                    <Link v-if="user" href="/dashboard" class="hover:underline">Översikt</Link>
                    <span v-if="user" class="text-slate-600">{{ user.name }}</span>
                    <Link v-else href="/login" class="hover:underline">Logga in</Link>
                </div>
            </nav>
        </header>

        <FlashMessage />

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8">
            <slot />
        </main>

        <footer class="border-t border-slate-200 py-4 text-center text-xs text-slate-500">
            Mimers
        </footer>
    </div>
</template>

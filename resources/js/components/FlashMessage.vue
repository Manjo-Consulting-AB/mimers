<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/*
 * Flashmeddelandet, se issue 51 § Beslut 5.
 *
 * Servern flashar en KOD (`status`), aldrig en färdig mening —
 * `back()->with('status', 'verification-link-sent')` — och den här
 * komponenten är det enda stället koden blir text. Koderna nedan är de som
 * faktiskt sätts någonstans i repot; en ny läggs till här den dag en
 * kontroller börjar sätta den, inte i förväg. Ett okänt värde renderar
 * ingenting.
 *
 * Ingen `flash.success`/`flash.error`/`flash.warning`: ingen kod sätter dem,
 * och en mekanism utan avsändare är en mekanism att riva.
 *
 * Texten är svensk rakt i komponenten. Issue 52 flyttar den till lang/ och
 * skickar den som delad prop — bygg ingen egen strängfil eller
 * konstantmodul i förväg.
 */
const messages = {
    'verification-link-sent': 'Ett nytt verifieringsmejl har skickats.',
    'magic-link-sent': 'Vi har skickat en inloggningslänk till din e-post.',
    'totp-confirmed': 'Tvåfaktorsinloggning är påslagen.',
    'totp-disabled': 'Tvåfaktorsinloggning är avstängd.',
    'session-expired': 'Din session hann gå ut. Försök igen.',
};

const text = computed(() => messages[usePage().props.flash.status] ?? null);
</script>

<template>
    <div
        v-if="text"
        role="status"
        class="border-b border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm text-emerald-900"
    >
        {{ text }}
    </div>
</template>

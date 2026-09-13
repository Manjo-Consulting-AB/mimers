<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Flashmeddelandet, se issue 51 § Beslut 5 och issue 52 § Beslut 4.
 *
 * Servern flashar en KOD (`status`), aldrig en färdig mening —
 * `back()->with('status', 'verification-link-sent')` — och den här
 * komponenten är det enda stället koden blir text. Nycklarna ligger under
 * `flash` i lang/{locale}/ui.php, en per kod som faktiskt sätts någonstans i
 * repot; en ny läggs till där den dag en kontroller börjar sätta den, inte i
 * förväg.
 *
 * En kod utan nyckel renderar den råa nyckeln (`flash.nagon-ny-kod`).
 * Regeln ägs av t() och gäller hela gränssnittet: en saknad översättning ska
 * synas, inte tystna.
 *
 * Ingen `flash.success`/`flash.error`/`flash.warning`: ingen kod sätter dem,
 * och en mekanism utan avsändare är en mekanism att riva.
 */
const { t } = useTranslations();

const code = computed(() => usePage().props.flash.status);

const text = computed(() => (code.value ? t(`flash.${code.value}`) : null));
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

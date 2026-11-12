<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
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
 *
 * Meddelandet bär alltså ingen allvarlighetsgrad, och ytan är
 * informationsrutan ur [[ADR-0042 Designsystemet]] § Beslut:
 * `--color-accent-soft` mot `--color-border`. Det gröna det bar förut
 * (emerald-paletten) har ingen roll i tokensystemet — `--color-success` är
 * OK-märket, inte en banneryta — och en färg utan roll är just den här
 * issuens ärende.
 */
const { t } = useTranslations();

/*
 * Issue 359 · ADR-0074 § 5. En flashkod kan bära en väg vidare: tabellen är
 * per kod och inte per yta, så på `/inbox` leder länken till samma sida. En
 * kod utan post ritas som förut, utan länk.
 */
const FLASH_LINKS = {
    'inbox-task-captured': '/inbox',
};

const code = computed(() => usePage().props.flash.status);

const text = computed(() => (code.value ? t(`flash.${code.value}`) : null));

const linkHref = computed(() => FLASH_LINKS[code.value] ?? null);
</script>

<template>
    <div
        v-if="text"
        role="status"
        class="border-b border-border bg-accent-soft px-4 py-3 text-center text-body text-ink"
    >
        {{ text }}

        <Link
            v-if="linkHref"
            :href="linkHref"
            class="ml-2 inline-flex min-h-11 items-center text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
        >
            {{ t(`flash_link.${code}`) }}
        </Link>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import CreateButton from './CreateButton.vue';
import NotificationBell from './NotificationBell.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Flikraden i botten, se [[ADR-0048 Mobilen och plusknappen]] § 1 och
 * [[M23 Mobilen och kartan]] § 151.
 *
 * **Fem platser: Översikt, Sök, plusknappen, Notiser och Meny.** Raden ritas
 * bara under `md:` (`md:hidden`), och skalet över brytpunkten möter exakt den
 * navigering det mötte före genomgången.
 *
 * **Plusknappen står i mitten sedan issue 152** · [[ADR-0048 Mobilen och
 * plusknappen]] § 2. Den är `CreateButton` och får sitt mål som en prop av
 * skalet — samma `create`-propp som sidhuvudets knapp, så de två kan inte göra
 * olika saker. En sida utan mål får ingen knapp, och platsen står kvar tom:
 * de fyra andra flyttar sig inte för att en sida inte får skapa något.
 *
 * **Notiserna är klockan själv** (issue 127), inte en avskrift av den:
 * `NotificationBell` ritas i sin flikform, så taltutan, siffran och panelen är
 * desamma här som i sidhuvudet. Att bygga en andra klocka hade gett två
 * ställen att hålla i takt, och de två hade glidit isär inom samma milstolpe.
 *
 * **Träffytan är `min-h-11`** — 44 px ur issue 68a § Beslut 3. En flik i en
 * rad man träffar med tummen är hela skälet till att raden finns.
 *
 * **Menyn öppnar sidomenyn och ingenting annat.** Knappen äger inget tillstånd
 * — AppLayout håller `menuOpen` och MobileMenu ritar menyn — och den skickar
 * med sig sitt eget element så att fokus kan lämnas tillbaka till *Meny* när
 * menyn stängs (issue 151).
 */
const props = defineProps({
    /* Sant medan sidomenyn är öppen — knappen annonserar sitt läge. */
    menuOpen: { type: Boolean, default: false },
    /* Plusknappens mål, ur App\Support\Frontend\CreateTarget, eller null. */
    create: { type: Object, default: null },
});

const emit = defineEmits(['open-menu', 'open-create']);

const { t } = useTranslations();
</script>

<template>
    <nav
        :aria-label="t('nav.tabbar')"
        class="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface md:hidden"
    >
        <ul class="mx-auto flex w-full max-w-3xl items-stretch justify-between">
            <li class="flex-1">
                <Link
                    href="/dashboard"
                    class="flex min-h-11 w-full flex-col items-center justify-center gap-0.5 px-2 py-1 text-meta text-ink-muted"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <path d="M3 10.5 12 3l9 7.5"></path>
                        <path d="M5 9.5V21h14V9.5"></path>
                    </svg>

                    {{ t('nav.dashboard') }}
                </Link>
            </li>

            <li class="flex-1">
                <Link
                    href="/search"
                    class="flex min-h-11 w-full flex-col items-center justify-center gap-0.5 px-2 py-1 text-meta text-ink-muted"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m20 20-3.5-3.5"></path>
                    </svg>

                    {{ t('nav.search') }}
                </Link>
            </li>

            <!--
                Plusknappens plats, se issue 152. Tom när sidan inte har ett
                mål: en plats som fylls med en knapp utan mål vore en knapp
                som inte gör något.
            -->
            <li v-if="create" class="flex-1">
                <CreateButton
                    class="w-full"
                    :create="create"
                    @open="emit('open-create', $event)"
                />
            </li>

            <li v-else class="flex-1" aria-hidden="true"></li>

            <li class="flex-1">
                <NotificationBell variant="tab" />
            </li>

            <li class="flex-1">
                <button
                    type="button"
                    class="flex min-h-11 w-full flex-col items-center justify-center gap-0.5 px-2 py-1 text-meta text-ink-muted"
                    aria-controls="sidomenyn"
                    :aria-expanded="props.menuOpen"
                    @click="emit('open-menu', $event.currentTarget)"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <path d="M4 7h16"></path>
                        <path d="M4 12h16"></path>
                        <path d="M4 17h16"></path>
                    </svg>

                    {{ t('nav.menu') }}
                </button>
            </li>
        </ul>
    </nav>
</template>

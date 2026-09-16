<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';
import { presetFor } from '../data/categoryPresets.js';

/*
 * Den färdiga kategoriuppsättningen, som ett kort överst på kategorisidan — se
 * issue 56b § Beslut 1, 2 och 4.
 *
 * **Vilken uppsättning som visas avgörs HÄR, i klienten** (Beslut 2). Localen
 * kommer ur den delade propen `locale` (issue 52) och typen ur pärmens `kind`;
 * `presetFor()` i resources/js/data/categoryPresets.js väljer, med tysta
 * reservval för en okänd locale och en okänd typ. Servern får aldrig veta
 * vilket språk eller vilken typ orden kom ifrån — den tar emot en lista med
 * namn och sparar dem. Därför finns ingen `preset`-prop: hade servern skickat
 * orden hade [[ADR-0004 Fria taggar och kategorier]] varit upphävd.
 *
 * **Kortet blockerar ingenting** (Beslut 4). Ingen modal, ingen overlay, inget
 * som måste besvaras — det är ett `<section>` ovanför trädet, och sidans
 * vanliga skapa-formulär fungerar bredvid det. Det är vad "kan tacka nej utan
 * att fastna" betyder. Sidan bestämmer om kortet ritas alls (tomt träd, ingen
 * tidigare nej, `can.manage`); komponenten bestämmer bara vad det säger.
 *
 * **POST:en skickar NAMNEN, inte ett "lägg in uppsättning X".** Servern har
 * ingen endpoint som betyder "segelbåt" — den tar en lista och skapar en rad
 * per namn (Beslut 1 och 3).
 *
 * **"Nej tack" är en `<Link method="delete">`**, samma mönster som
 * CategoryRow: rutten är en DELETE och Inertia skickar CSRF-tokenet åt oss.
 * Svaret är en omdirigering tillbaka, och sidan renderar om utan kortet
 * eftersom `presetDismissed` nu är sann.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /** Pärmens `kind` — ett värde ur App\Models\Container::KINDS, eller vad servern nu skickade. */
    kind: { type: String, required: true },
});

const { t } = useTranslations();
const page = usePage();

const preset = computed(() => presetFor(page.props.locale, props.kind));

const form = useForm({ categories: [] });

function apply() {
    form.transform(() => ({ categories: preset.value }));

    form.post(`/containers/${props.containerUlid}/categories/preset`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="mt-6 rounded border border-slate-300 bg-white p-4">
        <h2 class="text-lg font-semibold">{{ t('container.categories.preset_heading') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ t('container.categories.preset_description') }}</p>

        <!-- Förslaget i sin helhet, två nivåer. Den som vill se trädet innan
             hon trycker ska inte behöva gissa vad knappen gör. -->
        <ul class="mt-3 flex flex-col gap-1 text-sm">
            <li v-for="category in preset" :key="category.name">
                {{ category.name }}
                <span v-if="category.children" class="text-slate-500">
                    — {{ category.children.join(', ') }}
                </span>
            </li>
        </ul>

        <div class="mt-4 flex items-center gap-3">
            <button
                type="button"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                @click="apply"
            >
                {{ form.processing ? t('common.pending.default') : t('container.categories.preset_apply') }}
            </button>

            <Link
                :href="`/containers/${containerUlid}/categories/preset`"
                method="delete"
                as="button"
                preserve-scroll
                class="inline-flex min-h-11 items-center text-sm text-slate-700 underline"
            >
                {{ t('container.categories.preset_dismiss') }}
            </Link>
        </div>

        <!-- Ett nej på en pärm som hann få kategorier emellan, eller ett dubbelklick:
             servern svarar 422 på nyckeln `categories` och meningen ritas här. -->
        <p
            v-if="form.errors.categories"
            role="alert"
            class="mt-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ form.errors.categories }}
        </p>
    </section>
</template>

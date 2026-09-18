<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';
import { categoryPresets, presetFor } from '../data/categoryPresets.js';

/*
 * Den färdiga kategoriuppsättningen, som ett kort överst på kategorisidan — se
 * issue 56b § Beslut 1, 2 och 4, och issue 84 · [[ADR-0036 Containerns art]].
 *
 * **Vilka uppsättningar som erbjuds avgörs HÄR, i klienten** (Beslut 2).
 * Localen kommer ur den delade propen `locale` (issue 52);
 * resources/js/data/categoryPresets.js bär katalogen. Servern får aldrig veta
 * vilket språk orden kom ifrån — den tar emot en lista med namn och sparar
 * dem. Därför finns ingen `preset`-prop: hade servern skickat orden hade
 * [[ADR-0004 Fria taggar och kategorier]] varit upphävd.
 *
 * **VILKEN uppsättning som används väljer användaren, och det är issue 84.**
 * Före den valdes den av containerns `kind` — ett fält som [[ADR-0036
 * Containerns art]] gör fritt, och en fri art pekar inte ut någon mall.
 * [[ADR-0033 Produktens omfång]] § Beslut säger samma sak: mallarna "får
 * finnas kvar som en genväg användaren aktivt väljer, aldrig som en förvald
 * struktur systemet antar". Ingen förvald uppsättning, alltså: valet är tomt
 * tills hon pekat på en, och knappen är avstängd till dess.
 *
 * **Katalogens nycklar är uppsättningarnas identitet**, inte något systemet
 * slår upp ur containern — `presetFor()` nedan anropas med det val användaren
 * gjort, och etiketten i listan är nyckelns eget ord. Att flytta namnen in i
 * datafilen hade varit en omskrivning av resources/js/data/categoryPresets.js,
 * som ligger utanför den här issuns omfångsruta; se `Frågor och antaganden` i
 * PR:en. Den som vill ha namnen där i stället gör det i en egen issue.
 *
 * **`kind` tas emot men läses inte.** Sidan som ritar kortet skickar
 * containerns art; den proppen togs bort i det första försöket, men
 * resources/js/pages/Containers/Categories.vue ligger utanför rutan och
 * skickar den fortfarande — utan deklarationen hade värdet blivit ett
 * DOM-attribut på `<section>`. Att mallen inte längre härleds ur arten är
 * hela poängen med issue 84: proppen bärs för att sidan bär den, och inget
 * här grenar på den.
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
    /** Containerns art. Läses inte — se klasskommentaren. */
    kind: { type: String, required: true },
});

const { t } = useTranslations();
const page = usePage();

/*
 * Uppsättningarna på användarens språk. Bara `en` finns, se datafilen; reserven
 * för en locale utan katalog är samma `en` som `presetFor()` faller tillbaka
 * på, och den står här bara för att LISTAN över uppsättningar behöver
 * nycklarna — själva innehållet hämtas alltid med `presetFor()`.
 */
const presets = computed(() => {
    const catalog = categoryPresets[page.props.locale] ?? categoryPresets.en;

    return Object.keys(catalog).map((key) => ({
        key,
        label: key.charAt(0).toUpperCase() + key.slice(1),
        categories: catalog[key],
    }));
});

/*
 * Användarens val — klientstate, för det är hennes val och ingenting servern
 * vet om. Den tomma strängen betyder "ingenting valt än".
 */
const chosenKey = ref('');

const preset = computed(() =>
    chosenKey.value === '' ? null : presetFor(page.props.locale, chosenKey.value),
);

const form = useForm({ categories: [] });

function apply() {
    if (preset.value === null) {
        return;
    }

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

        <label for="category-preset" class="mt-4 block text-sm font-medium text-slate-800">
            {{ t('container.categories.preset_choose') }}
        </label>

        <select
            id="category-preset"
            v-model="chosenKey"
            class="mt-1 rounded border border-slate-300 bg-white px-3 py-2"
        >
            <option value="" disabled>{{ t('container.categories.preset_pick') }}</option>
            <option v-for="one in presets" :key="one.key" :value="one.key">{{ one.label }}</option>
        </select>

        <!-- Förslaget i sin helhet, två nivåer. Den som vill se trädet innan
             hon trycker ska inte behöva gissa vad knappen gör. Ingenting
             förhandsvisas förrän hon valt: ingen mall är förvald. -->
        <ul v-if="preset" class="mt-3 flex flex-col gap-1 text-sm">
            <li v-for="category in preset" :key="category.name">
                {{ category.name }}
                <span v-if="category.children" class="text-slate-600">
                    — {{ category.children.join(', ') }}
                </span>
            </li>
        </ul>

        <div class="mt-4 flex items-center gap-3">
            <button
                type="button"
                :disabled="form.processing || preset === null"
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

        <!-- Ett nej på en container som hann få kategorier emellan, eller ett dubbelklick:
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

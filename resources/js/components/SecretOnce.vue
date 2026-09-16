<script setup>
import { ref } from 'vue';

/*
 * En hemlighet som visas EN gång, se issue 65b § Beslut 2 och 3.
 *
 * Båda utgångarna ur systemet ger ut en hemlighet på samma sätt: en
 * kalenderadress som i praktiken är ett lösenord till pärmens uppgifter, och
 * en webhook-hemlighet som signerar varje leverans. Servern har dem bara i
 * klartext i svaret på skapandet (App\Models\CalendarFeed sparar en hash,
 * App\Models\WebhookEndpoint en krypterad kolumn som aldrig lämnar ut sig),
 * och den som tappat bort sin återkallar och skapar en ny. Därför en
 * gemensam komponent och inte två varianter som glider isär.
 *
 * **Värdet renderas som TEXT, aldrig som en länk.** Ingen `<a href>`, ingen
 * `window.location` och ingen URL-parameter: en hemlighet i en adressrad
 * hamnar i historiken, i `Referer` och i varje proxylogg på vägen (Beslut 3).
 *
 * **Ingen kopia sparas i komponenten.** `copied` är en kvittens på att
 * urklippsknappen gjorde sitt, inte hemligheten — den finns i `value`-propen
 * och i urklippet, och försvinner med sidan. Att lägga den i localStorage vore
 * att göra en engångshemlighet beständig, samma regel som 53b:s
 * återställningskoder följer (SakerhetsvyTest).
 *
 * Texten kommer färdigformulerad i props: `label` är vad hemligheten är,
 * `description` vad den används till, och `once` meningen om att den inte går
 * att se igen. Servern formulerar dem ur lang/ — ingen sträng finns i den här
 * filen (M10 § ingressen).
 */
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    description: { type: String, required: true },
    once: { type: String, required: true },
    copyLabel: { type: String, required: true },
    copiedLabel: { type: String, required: true },
});

const copied = ref(false);

function copy(value) {
    if (! navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(value).then(() => {
        copied.value = true;
    });
}
</script>

<template>
    <section class="mt-6 flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-4">
        <h2 class="text-sm font-medium text-amber-900">{{ label }}</h2>

        <code class="block overflow-x-auto rounded border border-amber-300 bg-white px-3 py-2 text-xs">{{ value }}</code>

        <button
            type="button"
            class="self-start text-sm font-medium text-blue-700 hover:underline"
            @click="copy(value)"
        >
            {{ copied ? copiedLabel : copyLabel }}
        </button>

        <p class="text-sm text-amber-900">{{ description }}</p>
        <p class="text-sm font-medium text-amber-900">{{ once }}</p>
    </section>
</template>

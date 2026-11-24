<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import FormField from '../components/FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Kontaktformuläret — /contact, M89 · [[ADR-0096 Kontaktformuläret]] § 1–6.
 *
 * **Sidan ritas utanför `auth` och `guest`**, som informationssidorna i
 * Info.vue, och layouten följer den: rubriken ur `contact.title` och
 * formuläret i en centrerad spalt under.
 *
 * **Ingen engelsk copy.** Etiketterna, hjälptexterna, bekräftelsen och felen
 * slås upp i `lang/en/ui.php` under `contact.*`. Kategorierna kommer som en
 * lista nycklar i `categories`-proppen, i ADR-0096:s ordning, och etiketten
 * per nyckel ur `contact.categories.<nyckel>`.
 *
 * **En inloggad ser sitt namn och sin adress men kan inte ändra dem.**
 * Fälten ritas inte alls för henne; servern läser identiteten ur sessionen
 * och ignorerar allt i kroppen. Fälten `name` och `email` skickas därför
 * bara med som tomma strängar för en gäst — de fylls av henne.
 *
 * **Inskickningen är JSON, inte en Inertia-visit.** Servern svarar
 * API-höljet (AGENTS.md § Felformat i API:et), så felet blir en kod vi
 * översätter här i stället för en färdig mening. Det gör också att det hon
 * skrivit står kvar i fälten när något går fel. Samma form som
 * components/HelpFeedback.vue, inklusive CSRF-tokenet ur kakan — Inertias
 * router sätter det själv, men `fetch` gör det inte.
 *
 * **Skräpsvaret går inte att skilja från ett riktigt.** Servern svarar
 * `{ "status": "sent" }` både för ett levererat ärende och för en
 * honeypot- eller tidsfälleträff; vyn visar bekräftelsen i båda fallen.
 * Fälten `website` (honeypot) och `rendered_at` (serverns krypterade
 * tidsstämpel) följer med i kroppen utan att ritas.
 */

const props = defineProps({
    categories: { type: Array, required: true },
    rendered_at: { type: String, required: true },
    name: { type: String, default: null },
    email: { type: String, default: null },
});

const { t } = useTranslations();

const form = ref({
    category: '',
    message: '',
    name: '',
    email: '',
    website: '',
    rendered_at: props.rendered_at,
});

const sending = ref(false);
const sent = ref(false);
const generalError = ref('');
const errors = ref({});

const signedIn = computed(() => props.name !== null && props.email !== null);

/* Valideringskoderna ur API-höljet → meningen fältet möter. */
const FIELD_ERROR_KEYS = {
    'validation.required': 'contact.errors.required',
    'validation.email': 'contact.errors.email',
    'validation.max': 'contact.errors.max',
    'validation.in': 'contact.errors.category',
};

/* Koderna utan ett fält → raden under formuläret. */
const GENERAL_ERROR_KEYS = {
    'contact.send_failed': 'contact.send_failed',
    'auth.too_many_attempts': 'contact.errors.too_many',
};

/* Kakan är krypterad och URL-kodad av Laravel; headern dekrypteras på servern. */
function xsrfToken() {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? '' : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/* Första felet per fält, översatt ur koden och regelns data. */
function mapFieldErrors(payload) {
    const fields = payload?.error?.data?.fields ?? {};
    const mapped = {};

    for (const [field, failures] of Object.entries(fields)) {
        const failure = Array.isArray(failures) ? failures[0] : null;

        mapped[field] = t(
            FIELD_ERROR_KEYS[failure?.code] ?? 'contact.errors.invalid',
            failure?.data ?? {},
        );
    }

    return mapped;
}

async function submit() {
    sending.value = true;
    sent.value = false;
    generalError.value = '';
    errors.value = {};

    try {
        const response = await fetch('/contact', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(form.value),
        });

        let data = null;

        try {
            data = await response.json();
        } catch {
            data = null;
        }

        if (response.status === 422) {
            errors.value = mapFieldErrors(data);

            return;
        }

        if (!response.ok) {
            const code = data?.error?.code ?? 'unknown';
            generalError.value = t(
                GENERAL_ERROR_KEYS[code] ?? 'contact.errors.invalid',
                data?.error?.data ?? {},
            );

            return;
        }

        sent.value = true;
    } catch {
        generalError.value = t('contact.errors.invalid');
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Head :title="t('contact.title')" />

    <div class="py-12 text-center">
        <h1 class="text-3xl font-semibold">{{ t('contact.title') }}</h1>

        <p class="mt-3 text-slate-600">{{ t('contact.intro') }}</p>

        <p
            v-if="sent"
            role="status"
            class="mx-auto mt-6 max-w-2xl rounded-control border border-border bg-accent-soft px-4 py-3 text-left text-body"
        >
            {{ t('contact.sent') }}
        </p>

        <form
            v-else
            class="mx-auto mt-6 flex max-w-2xl flex-col gap-4 text-left"
            @submit.prevent="submit"
        >
            <FormField v-slot="{ describedBy }" :label="t('contact.category')" id="category" :error="errors.category">
                <select
                    id="category"
                    v-model="form.category"
                    :aria-describedby="describedBy"
                    name="category"
                    required
                    class="w-full rounded-control border border-border px-3 py-2"
                >
                    <option value="" disabled>{{ t('contact.category_placeholder') }}</option>
                    <option v-for="key in categories" :key="key" :value="key">
                        {{ t(`contact.categories.${key}`) }}
                    </option>
                </select>
            </FormField>

            <p v-if="signedIn" class="text-body text-ink-muted">
                {{ t('contact.signed_in_as', { name, email }) }}
            </p>

            <template v-else>
                <FormField v-slot="{ describedBy }" :label="t('contact.name')" id="name" :error="errors.name">
                    <input
                        id="name"
                        v-model="form.name"
                        :aria-describedby="describedBy"
                        type="text"
                        name="name"
                        autocomplete="name"
                        maxlength="100"
                        required
                        class="w-full rounded-control border border-border px-3 py-2"
                    >
                </FormField>

                <FormField v-slot="{ describedBy }" :label="t('contact.email')" id="email" :error="errors.email">
                    <input
                        id="email"
                        v-model="form.email"
                        :aria-describedby="describedBy"
                        type="email"
                        name="email"
                        autocomplete="email"
                        required
                        class="w-full rounded-control border border-border px-3 py-2"
                    >
                </FormField>
            </template>

            <FormField v-slot="{ describedBy }" :label="t('contact.message')" id="message" :error="errors.message">
                <textarea
                    id="message"
                    v-model="form.message"
                    :aria-describedby="describedBy"
                    name="message"
                    rows="6"
                    maxlength="5000"
                    required
                    class="w-full rounded-control border border-border px-3 py-2"
                ></textarea>

                <p class="text-meta text-ink-muted">{{ t('contact.message_help') }}</p>
            </FormField>

            <!-- Honeypot: osynligt för en människa, fyllt av en bot. -->
            <input
                id="website"
                v-model="form.website"
                type="text"
                name="website"
                tabindex="-1"
                autocomplete="off"
                aria-hidden="true"
                class="hidden"
            >

            <!-- Serverns tid, krypterad — tidsfällan mäter mot den. -->
            <input id="rendered_at" :value="form.rendered_at" type="hidden" name="rendered_at">

            <p v-if="generalError" role="alert" class="text-body text-danger">{{ generalError }}</p>

            <button
                type="submit"
                :disabled="sending"
                class="inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 font-medium text-ink-on-accent disabled:opacity-50"
            >
                {{ sending ? t('common.pending.default') : t('contact.submit') }}
            </button>
        </form>
    </div>
</template>

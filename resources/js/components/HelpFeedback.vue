<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiButton from './UiButton.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * *Was this helpful?* på artikelsidan — M82 · issue 471,
 * [[ADR-0094 Hjälpcentret]] § 7.
 *
 * **Valet sparas direkt, texten med Save.** Ja och Nej skickas så fort de
 * klickas, och orsaken sparas när den väljs. Kommentaren sparas bara när
 * *Save* trycks (Tonys regel: val sparas direkt, text med Save). Valt svar
 * visas som valt och kan ändras — ett nytt Ja eller Nej uppdaterar samma rad,
 * eftersom servern känner igen artikeln i sessionen.
 *
 * **Raden får sin ULID ur svaret.** Första Ja/Nej ger `201` med radens
 * `ulid`; det är den orsaken och kommentaren sedan `PATCH`ar. Ett andra
 * Ja/Nej i samma session ger `200` med samma ULID.
 *
 * **Fälten hör till ett Nej.** Orsaksradion och kommentaren ritas bara när
 * svaret är Nej, och *Thanks for letting us know* bara när det är Ja. Texten
 * säger också att kommentaren sparas i 90 dagar. En gäst möts av en länk till
 * inloggningen i stället för fältet — servern nekar ändå en gästs kommentar
 * med `help.comment_requires_login`.
 *
 * **Felen översätts ur koden.** Servern svarar API-höljet
 * (AGENTS.md § Felformat i API:et), och raden under knapparna visar en mening
 * ur `help.feedback.error.*` i stället för den råa koden. En 429 bär
 * `auth.too_many_attempts` och får sin egen mening.
 *
 * **Kakan är CSRF-beviset.** Rutterna ligger i web.php och kräver en
 * CSRF-token. Inertias router sätter den själv, men `fetch` gör det inte, så
 * `XSRF-TOKEN` läses ur kakan och skickas som `X-XSRF-TOKEN` — samma värde
 * axios hade skickat. Ingen ny katalog och ingen ny meta-tagg i rotvyn.
 */

const props = defineProps({
    articleId: { type: String, required: true },
});

const { t } = useTranslations();

/* Orsakerna i den ordning ytan visar dem; samma lista som serverns CHECK. */
const REASONS = ['unclear', 'incomplete', 'outdated', 'incorrect', 'missing'];

/* Felkoden ur API-höljet → meningen användaren möter. */
const ERROR_KEYS = {
    'auth.too_many_attempts': 'help.feedback.error.too_many',
    'help.comment_requires_login': 'help.feedback.error.comment_requires_login',
    'help.feedback_not_negative': 'help.feedback.error.not_negative',
    'validation.failed': 'help.feedback.error.invalid',
};

/* `null` innan något valts, annars `true` eller `false`. */
const helpful = ref(null);
const reason = ref(null);
const comment = ref('');
const ulid = ref(null);
const error = ref(null);
const savingComment = ref(false);

const commentId = `help-feedback-comment-${props.articleId}`;

const loggedIn = computed(() => Boolean(usePage().props.auth?.user));

const errorMessage = computed(() => (error.value === null
    ? ''
    : t(ERROR_KEYS[error.value] ?? 'help.feedback.error.generic')));

/* Kakan är krypterad och URL-kodad av Laravel; headern dekrypteras på servern. */
function xsrfToken() {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? '' : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/*
 * Ett anrop mot en av de två rutterna. Svaret är JSON; ett fel blir den kod
 * raden under knapparna översätter, aldrig en färdig mening från servern.
 */
async function send(url, method, body) {
    try {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });

        let data = null;

        try {
            data = await response.json();
        } catch {
            data = null;
        }

        if (!response.ok) {
            error.value = data?.error?.code ?? 'unknown';

            return null;
        }

        error.value = null;

        return data;
    } catch {
        error.value = 'unknown';

        return null;
    }
}

/*
 * Ja eller Nej. Svaret sparas direkt; misslyckas det ställs föregående val
 * tillbaka, så att ytan aldrig visar något servern inte har.
 */
async function chooseHelpful(value) {
    const previous = {
        helpful: helpful.value,
        reason: reason.value,
        comment: comment.value,
    };

    helpful.value = value;

    if (value) {
        reason.value = null;
        comment.value = '';
    }

    const data = await send(`/help/articles/${props.articleId}/feedback`, 'POST', { helpful: value });

    if (data === null) {
        helpful.value = previous.helpful;
        reason.value = previous.reason;
        comment.value = previous.comment;

        return;
    }

    if (typeof data.ulid === 'string') {
        ulid.value = data.ulid;
    }
}

/* Orsaken sparas i det ögonblick den väljs, utan knapp. */
async function chooseReason(value) {
    if (ulid.value === null) {
        return;
    }

    const previous = reason.value;

    reason.value = value;

    const data = await send(`/help/feedback/${ulid.value}`, 'PATCH', { reason: value });

    if (data === null) {
        reason.value = previous;
    }
}

/* Kommentaren sparas bara här — aldrig medan man skriver. */
async function saveComment() {
    if (ulid.value === null || savingComment.value) {
        return;
    }

    savingComment.value = true;

    try {
        await send(`/help/feedback/${ulid.value}`, 'PATCH', { comment: comment.value });
    } finally {
        savingComment.value = false;
    }
}
</script>

<template>
    <section class="rounded-card border border-border bg-surface p-4">
        <h2 class="text-title font-semibold">{{ t('help.feedback.question') }}</h2>

        <div class="mt-3 flex flex-wrap gap-2">
            <UiButton
                type="button"
                :variant="helpful === true ? 'primary' : 'secondary'"
                :aria-pressed="helpful === true ? 'true' : 'false'"
                @click="chooseHelpful(true)"
            >
                {{ t('help.feedback.yes') }}
            </UiButton>

            <UiButton
                type="button"
                :variant="helpful === false ? 'primary' : 'secondary'"
                :aria-pressed="helpful === false ? 'true' : 'false'"
                @click="chooseHelpful(false)"
            >
                {{ t('help.feedback.no') }}
            </UiButton>
        </div>

        <p v-if="helpful === true" class="mt-3 text-body text-ink-muted">
            {{ t('help.feedback.thanks') }}
        </p>

        <div v-if="helpful === false" class="mt-4">
            <div class="space-y-1">
                <label
                    v-for="key in REASONS"
                    :key="key"
                    :for="`help-feedback-reason-${key}`"
                    class="flex min-h-11 items-center gap-2 text-body text-ink"
                >
                    <input
                        :id="`help-feedback-reason-${key}`"
                        type="radio"
                        name="help-feedback-reason"
                        :value="key"
                        :checked="reason === key"
                        class="h-4 w-4 border-border text-accent"
                        @change="chooseReason(key)"
                    >
                    <span>{{ t(`help.feedback.reasons.${key}`) }}</span>
                </label>
            </div>

            <div v-if="loggedIn" class="mt-4">
                <label :for="commentId" class="text-body font-medium text-ink">
                    {{ t('help.feedback.comment_label') }}
                </label>

                <textarea
                    :id="commentId"
                    v-model="comment"
                    rows="3"
                    maxlength="1000"
                    class="mt-1 w-full rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                />

                <p class="mt-1 text-meta text-ink-subtle">{{ t('help.feedback.privacy') }}</p>

                <UiButton
                    type="button"
                    class="mt-2"
                    :disabled="savingComment"
                    @click="saveComment"
                >
                    {{ t('help.feedback.save') }}
                </UiButton>
            </div>

            <Link
                v-else
                href="/login"
                class="mt-4 inline-flex min-h-11 items-center font-medium text-accent hover:underline"
            >
                {{ t('help.feedback.login_to_comment') }}
            </Link>
        </div>

        <p v-if="error" role="alert" class="mt-2 text-body text-danger">
            {{ errorMessage }}
        </p>
    </section>
</template>

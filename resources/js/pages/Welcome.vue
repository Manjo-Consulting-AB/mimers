<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Startsidan, se issue 51 § Beslut 11 och issue 53a § Beslut 3. Komponent-
 * namnet `Welcome` och rutten `/` ligger fast — tests/Feature/SkeletonTest.php
 * assertar båda.
 *
 * Sidan är publik och renderas för både en gäst och en inloggad användare;
 * den byter bara mål. Att skicka en inloggad vidare från /register eller
 * /login är `guest`-middlewarens jobb, inte den här sidans.
 *
 * Gästen får tre vägar in — lösenord, registrering och magic link — och inget
 * mer. Ingen inbäddad inloggningsform: en andra plats som postar till /login
 * är en andra plats där fältnamn och fel kan glida isär, se issue 53a
 * § Beslut 3.
 */
const { t } = useTranslations();
const user = computed(() => usePage().props.auth.user);
</script>

<template>
    <AppLayout>
        <Head :title="t('common.brand')" />

        <div class="py-12 text-center">
            <h1 class="text-3xl font-semibold">{{ t('common.brand') }}</h1>
            <p class="mt-3 text-slate-600">{{ t('common.tagline') }}</p>

            <Link
                v-if="user"
                href="/dashboard"
                class="mt-8 inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
            >
                {{ t('common.to_dashboard') }}
            </Link>

            <template v-else>
                <Link
                    href="/login"
                    class="mt-8 inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
                >
                    {{ t('nav.login') }}
                </Link>

                <div class="mt-6 flex flex-col items-center gap-2 text-sm">
                    <Link href="/register" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('auth.register.link') }}</Link>
                    <Link href="/login/magic-link" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                        {{ t('auth.magic_link.link') }}
                    </Link>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
defineProps({
    version: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <main class="flex min-h-full items-center justify-center bg-slate-50 p-8">
        <div class="text-center">
            <h1 class="text-3xl font-semibold text-slate-900">Mimers</h1>
            <p class="mt-2 text-slate-600">Skalet står. Vyerna byggs i milstolpe M10.</p>
            <p class="mt-6 font-mono text-xs text-slate-400">{{ version }}</p>
        </div>
    </main>
</template>

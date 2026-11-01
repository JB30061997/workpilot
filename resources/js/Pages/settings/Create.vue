<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="settings - Create" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            settings
        </h1>

        <p class="mt-2 text-gray-500">
            Page Create - USINEPILOT
        </p>
    </main>
</template>

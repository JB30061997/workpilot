<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Administration / Equipes - Index" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Administration / Equipes
        </h1>

        <p class="mt-2 text-gray-500">
            Page Index - USINEPILOT
        </p>
    </main>
</template>

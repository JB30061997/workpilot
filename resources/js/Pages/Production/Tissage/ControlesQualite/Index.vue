<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Production / Tissage / ControlesQualite - Index" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Production / Tissage / ControlesQualite
        </h1>

        <p class="mt-2 text-gray-500">
            Page Index - USINEPILOT
        </p>
    </main>
</template>

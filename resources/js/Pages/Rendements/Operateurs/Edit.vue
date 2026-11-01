<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Rendements / Operateurs - Edit" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Rendements / Operateurs
        </h1>

        <p class="mt-2 text-gray-500">
            Page Edit - USINEPILOT
        </p>
    </main>
</template>

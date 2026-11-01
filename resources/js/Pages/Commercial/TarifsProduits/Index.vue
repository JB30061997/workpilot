<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Commercial / TarifsProduits - Index" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Commercial / TarifsProduits
        </h1>

        <p class="mt-2 text-gray-500">
            Page Index - USINEPILOT
        </p>
    </main>
</template>

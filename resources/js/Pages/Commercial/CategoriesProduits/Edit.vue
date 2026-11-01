<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Commercial / CategoriesProduits - Edit" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Commercial / CategoriesProduits
        </h1>

        <p class="mt-2 text-gray-500">
            Page Edit - USINEPILOT
        </p>
    </main>
</template>

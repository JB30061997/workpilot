<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    donnees?: unknown;
}>();
</script>

<template>
    <Head title="Achats / AdressesFournisseurs - Show" />

    <main class="p-6">
        <h1 class="text-2xl font-semibold">
            Achats / AdressesFournisseurs
        </h1>

        <p class="mt-2 text-gray-500">
            Page Show - USINEPILOT
        </p>
    </main>
</template>

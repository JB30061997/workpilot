<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
defineProps<{titre:string,urlBase:string,enregistrement:Record<string,unknown>}>();
</script>
<template><Head :title="titre"/><section class="p-6 space-y-4"><Link :href="urlBase" class="underline">Retour</Link><h1 class="text-2xl font-semibold">{{titre}} — Détail</h1><dl class="grid grid-cols-1 md:grid-cols-2 gap-3"><div v-for="(valeur,cle) in enregistrement" :key="cle" class="rounded border p-3"><dt class="text-sm text-slate-500">{{cle}}</dt><dd class="break-words">{{valeur??'—'}}</dd></div></dl></section></template>

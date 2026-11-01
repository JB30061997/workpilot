<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps<{titre:string, urlBase:string, champs:string[], enregistrement?:Record<string,unknown>, lectureSeule:boolean}>();
const initial: Record<string, unknown> = {};
for(const champ of props.champs) initial[champ] = props.enregistrement?.[champ] ?? '';
const form = useForm(initial);
const edition = Boolean(props.enregistrement?.id);
function soumettre(){if(props.lectureSeule)return;if(edition)form.put(`${props.urlBase}/${props.enregistrement?.id}`);else form.post(props.urlBase);}
</script>
<template>
<Head :title="titre"/><section class="p-6 max-w-4xl space-y-5"><Link :href="urlBase" class="underline">Retour</Link><h1 class="text-2xl font-semibold">{{edition?'Modifier':'Créer'}} — {{titre}}</h1>
<p v-if="lectureSeule" class="text-amber-700">Opération désactivée en attente du service métier.</p>
<form @submit.prevent="soumettre" class="grid grid-cols-1 md:grid-cols-2 gap-4"><label v-for="champ in champs" :key="champ" class="flex flex-col gap-1"><span class="text-sm">{{champ.replaceAll('_',' ')}}</span><input v-model="form[champ]" :disabled="lectureSeule" class="border rounded p-2" :type="champ.startsWith('date_')?'date':'text'"/><span v-if="form.errors[champ]" class="text-red-600 text-xs">{{form.errors[champ]}}</span></label><div class="md:col-span-2"><button :disabled="lectureSeule||form.processing" class="bg-slate-900 text-white rounded px-5 py-2 disabled:opacity-40">Enregistrer</button></div></form></section>
</template>

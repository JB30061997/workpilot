<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
const props = defineProps<{donnees:{data:Record<string,unknown>[],links:{url:string|null,label:string,active:boolean}[]}, recherche:string, champs:string[], lectureSeule:boolean, titre:string, urlBase:string}>();
const recherche = ref(props.recherche ?? '');
function filtrer(){ router.get(props.urlBase,{recherche:recherche.value},{preserveState:true,replace:true}); }
function supprimer(id:unknown){if(confirm('Supprimer cet enregistrement ?')) router.delete(`${props.urlBase}/${id}`);}
</script>
<template>
<Head :title="titre" />
<section class="p-6 space-y-5">
 <div class="flex flex-wrap justify-between items-center gap-3"><h1 class="text-2xl font-semibold">{{ titre }}</h1><Link v-if="!lectureSeule" :href="`${urlBase}/create`" class="rounded bg-slate-900 px-4 py-2 text-white">Nouveau</Link></div>
 <form @submit.prevent="filtrer" class="flex gap-2"><input v-model="recherche" placeholder="Rechercher..." class="border rounded p-2 w-full max-w-sm"/><button class="border rounded px-4">Rechercher</button></form>
 <div class="overflow-x-auto rounded border"><table class="w-full text-sm"><thead class="bg-slate-100"><tr><th class="p-3 text-left">ID</th><th v-for="c in champs.slice(0,7)" :key="c" class="p-3 text-left">{{c.replaceAll('_',' ')}}</th><th class="p-3">Actions</th></tr></thead><tbody><tr v-for="item in donnees.data" :key="String(item.id)" class="border-t"><td class="p-3">{{item.id}}</td><td v-for="c in champs.slice(0,7)" :key="c" class="p-3">{{item[c] ?? '—'}}</td><td class="p-3 whitespace-nowrap"><Link :href="`${urlBase}/${item.id}`" class="underline mr-3">Voir</Link><Link v-if="!lectureSeule" :href="`${urlBase}/${item.id}/edit`" class="underline mr-3">Modifier</Link><button v-if="!lectureSeule" @click="supprimer(item.id)" class="text-red-600">Supprimer</button></td></tr><tr v-if="!donnees.data.length"><td :colspan="9" class="p-6 text-center">Aucune donnée</td></tr></tbody></table></div>
 <nav class="flex flex-wrap gap-2"><template v-for="(l,i) in donnees.links" :key="i"><Link v-if="l.url" :href="l.url" class="border rounded px-3 py-1" :class="{'font-bold bg-slate-100':l.active}" v-html="l.label"/><span v-else class="border rounded px-3 py-1 opacity-40" v-html="l.label"/></template></nav>
 <p v-if="lectureSeule" class="text-amber-700 text-sm">Lecture seule : opérations industrielles en attente des services métier sécurisés.</p>
</section>
</template>

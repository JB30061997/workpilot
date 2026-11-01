<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Factory, Warehouse, ShoppingCart, Truck, Settings2, BarChart3, ArrowUpRight, Layers3, Printer, Scissors } from '@lucide/vue';
const modules = [
 {titre:'Achats & fournisseurs', description:'Matières premières, commandes et réceptions', href:'/erp/commande-achat', icon:ShoppingCart},
 {titre:'Gestion des stocks', description:'Dépôts, lots et mouvements', href:'/erp/depot', icon:Warehouse},
 {titre:'Extrusion', description:'Consommations, bobines et production', href:'/erp/production-extrusion', icon:Factory},
 {titre:'Tissage', description:'Rouleaux, grammage et rendement', href:'/erp/production-tissage', icon:Layers3},
 {titre:'Impression', description:'Ordres et rouleaux imprimés', href:'/erp/production-impression', icon:Printer},
 {titre:'Coupe', description:'Production des sacs et contrôle', href:'/erp/production-coupe', icon:Scissors},
 {titre:'Commercial', description:'Clients, produits et commandes', href:'/erp/commande-client', icon:BarChart3},
 {titre:'Livraisons', description:'Planning, bons et expéditions', href:'/erp/bon-livraison', icon:Truck},
];
</script>
<template>
  <Head title="Tableau de bord"/>
  <main class="erp-dashboard p-5 md:p-8">
    <div class="mb-7 flex flex-wrap items-center justify-between gap-3">
      <div><p class="mb-1 text-xs font-semibold uppercase tracking-widest text-blue-600">USINEPILOT · ERP INDUSTRIEL</p><h1 class="text-2xl font-bold text-slate-900">Tableau de bord</h1><p class="mt-1 text-sm text-slate-500">Pilotez les achats, les stocks, la production et les livraisons.</p></div>
      <span class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-500">Vue générale</span>
    </div>
    <div class="mb-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="(t,i) in ['Commandes clients','Matières en stock','Production du jour','Livraisons prévues']" :key="t" class="erp-kpi">
        <p class="text-xs font-medium text-slate-500">{{ t }}</p><div class="mt-4 text-2xl font-bold text-slate-800">—</div><p class="mt-2 text-xs text-slate-400">Données à connecter</p>
      </div>
    </div>
    <div class="mb-4 flex items-center gap-2"><Settings2 class="size-4 text-blue-600"/><h2 class="text-base font-semibold text-slate-800">Modules de gestion</h2></div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <Link v-for="m in modules" :key="m.titre" :href="m.href" class="erp-module group">
        <div class="flex items-start justify-between"><div class="rounded-lg bg-blue-50 p-3 text-blue-700"><component :is="m.icon" class="size-5"/></div><ArrowUpRight class="size-4 text-slate-400 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5"/></div>
        <h3 class="mt-5 font-semibold text-slate-800">{{m.titre}}</h3><p class="mt-1 text-xs leading-5 text-slate-500">{{m.description}}</p>
      </Link>
    </div>
  </main>
</template>

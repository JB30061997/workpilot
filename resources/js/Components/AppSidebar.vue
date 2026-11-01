<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import * as Icons from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
const groupes = [{"title": "Administration", "icon": "Building2", "links": [{"label": "Societe", "url": "/erp/societe"}, {"label": "Usine", "url": "/erp/usine"}, {"label": "Section", "url": "/erp/section"}, {"label": "Service", "url": "/erp/service"}, {"label": "Equipe", "url": "/erp/equipe"}, {"label": "Operateur", "url": "/erp/operateur"}]}, {"title": "Achats & matières", "icon": "ShoppingCart", "links": [{"label": "Fournisseur", "url": "/erp/fournisseur"}, {"label": "Matière Premiere", "url": "/erp/matiere-premiere"}, {"label": "CategorieMatière ", "url": "/erp/categorie-matiere"}, {"label": "TarifFournisseur", "url": "/erp/tarif-fournisseur"}, {"label": "Commande Achat", "url": "/erp/commande-achat"}, {"label": "ReceptionAchat", "url": "/erp/reception-achat"}]}, {"title": "Stocks", "icon": "Warehouse", "links": [{"label": "Depot", "url": "/erp/depot"}, {"label": "Emplacement", "url": "/erp/emplacement"}, {"label": "Article", "url": "/erp/article"}, {"label": "Mouvement stock", "url": "/erp/mouvement-stock"}, {"label": "Transfert stock", "url": "/erp/transfert-stock"}, {"label": "Inventaire stock", "url": "/erp/inventaire-stock"}]}, {"title": "Extrusion", "icon": "Factory", "links": [{"label": "Production Extrusion", "url": "/erp/production-extrusion"}, {"label": "ConsommationExtrusion", "url": "/erp/consommation-extrusion"}, {"label": "BobineExtrusion", "url": "/erp/bobine-extrusion"}]}, {"title": "Tissage", "icon": "Layers3", "links": [{"label": "Production Tissage", "url": "/erp/production-tissage"}, {"label": "ConsommationTissage", "url": "/erp/consommation-tissage"}, {"label": "RouleauTissage", "url": "/erp/rouleau-tissage"}]}, {"title": "Impression", "icon": "Printer", "links": [{"label": "Production Impression", "url": "/erp/production-impression"}, {"label": "ConsommationImpression", "url": "/erp/consommation-impression"}]}, {"title": "Coupe", "icon": "Scissors", "links": [{"label": "Production Coupe", "url": "/erp/production-coupe"}, {"label": "ConsommationCoupe", "url": "/erp/consommation-coupe"}]}, {"title": "Commercial", "icon": "Users", "links": [{"label": "Client", "url": "/erp/client"}, {"label": "Commercial", "url": "/erp/commercial"}, {"label": "Produit", "url": "/erp/produit"}, {"label": "Commande Client", "url": "/erp/commande-client"}, {"label": "DevisClient", "url": "/erp/devis-client"}]}, {"title": "Planification", "icon": "CalendarDays", "links": [{"label": "OrdreFabrication", "url": "/erp/ordre-fabrication"}, {"label": "PlanningProduction ", "url": "/erp/planning-production"}, {"label": "PlanningLivraison", "url": "/erp/planning-livraison"}]}, {"title": "Livraisons", "icon": "Truck", "links": [{"label": "Bons de livraison", "url": "/erp/bon-livraison"}, {"label": "Expedition", "url": "/erp/expedition"}, {"label": "RetourClient", "url": "/erp/retour-client"}]}, {"title": "Machines & maintenance", "icon": "Wrench", "links": [{"label": "Machine", "url": "/erp/machine"}, {"label": "PanneMachine", "url": "/erp/panne-machine"}, {"label": "OrdreMaintenance", "url": "/erp/ordre-maintenance"}]}, {"title": "Qualité & déchets", "icon": "ShieldCheck", "links": [{"label": "NonConformite", "url": "/erp/non-conformite"}, {"label": "DechetProduction ", "url": "/erp/dechet-production"}]}];
const page = usePage();
const urlActuelle = computed(() => page.url.split('?')[0]);
const ouvert = ref<string | null>(null);
function icone(nom: string) { return (Icons as Record<string, any>)[nom] || Icons.Folder; }
function estActif(g: typeof groupes[number]) { return g.links.some(l => urlActuelle.value === l.url || urlActuelle.value.startsWith(l.url + '/')); }
</script>
<template>
  <Sidebar collapsible="icon" variant="inset" class="erp-sidebar">
    <SidebarHeader class="erp-sidebar-head">
      <SidebarMenu><SidebarMenuItem><SidebarMenuButton size="lg" as-child>
        <Link href="/dashboard"><AppLogo /></Link>
      </SidebarMenuButton></SidebarMenuItem></SidebarMenu>
    </SidebarHeader>
    <SidebarContent class="px-2 py-3">
      <Link href="/dashboard" class="erp-nav-link" :class="{ 'erp-nav-active': urlActuelle === '/dashboard' }">
        <component :is="Icons.LayoutDashboard" class="size-4 shrink-0"/><span>Tableau de bord</span>
      </Link>
      <div v-for="g in groupes" :key="g.title" class="mt-1">
        <button type="button" class="erp-nav-link w-full" :class="{ 'erp-nav-active': estActif(g) }" @click="ouvert = ouvert === g.title ? null : g.title">
          <component :is="icone(g.icon)" class="size-4 shrink-0"/>
          <span class="flex-1 truncate text-left">{{ g.title }}</span>
          <component :is="Icons.ChevronDown" class="size-3 transition-transform" :class="{ 'rotate-180': ouvert === g.title || (ouvert === null && estActif(g)) }"/>
        </button>
        <div v-if="ouvert === g.title || (ouvert === null && estActif(g))" class="erp-submenu">
          <Link v-for="l in g.links" :key="l.url" :href="l.url" class="erp-sub-link" :class="{ 'erp-sub-active': urlActuelle === l.url || urlActuelle.startsWith(l.url + '/') }">{{ l.label }}</Link>
        </div>
      </div>
    </SidebarContent>
    <SidebarFooter class="erp-sidebar-footer"><NavUser /></SidebarFooter>
  </Sidebar>
  <slot />
</template>

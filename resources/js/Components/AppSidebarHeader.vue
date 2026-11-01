<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Factory, Clock3 } from '@lucide/vue';
import type { BreadcrumbItem } from '@/types';
withDefaults(defineProps<{breadcrumbs?: BreadcrumbItem[]}>(), {breadcrumbs:()=>[]});
const page=usePage();
const titre=computed(()=> (page.url.split('?')[0].split('/').filter(Boolean).slice(-1)[0] || 'Tableau de bord').replaceAll('-', ' '));
</script>
<template>
  <header class="erp-topbar flex h-16 shrink-0 items-center justify-between gap-3 px-5">
    <div class="flex min-w-0 items-center gap-3">
      <SidebarTrigger class="shrink-0"/>
      <div class="hidden h-6 w-px bg-slate-200 sm:block"></div>
      <div class="min-w-0"><p class="truncate text-sm font-semibold capitalize text-slate-800">{{ titre }}</p><p class="text-[11px] text-slate-500">Gestion industrielle · USINEPILOT</p></div>
    </div>
    <div class="flex items-center gap-2 text-xs text-slate-500"><Factory class="size-4 text-blue-600"/><span class="hidden sm:inline">Espace ERP</span></div>
  </header>
</template>

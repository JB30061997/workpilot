<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { CheckCircle2, ChevronLeft, ChevronRight, CircleDot, Filter, GitBranch, Layers3, Pencil, RotateCcw, Search, SlidersHorizontal, UserRound, Workflow } from "lucide-vue-next";
import { computed, reactive } from "vue";

const props = defineProps({ workflows: Object, filters: Object, projects: Array, creators: Array, summary: Object });
const form = reactive({
    search: props.filters.search || "",
    status: props.filters.status || "",
    project_id: props.filters.project_id || "",
    creator_id: props.filters.creator_id || "",
    sort: props.filters.sort || "updated_at",
    direction: props.filters.direction || "desc",
    per_page: props.filters.per_page || 15,
});
const hasFilters = computed(() => Boolean(form.search || form.status || form.project_id || form.creator_id || form.sort !== "updated_at" || form.direction !== "desc" || Number(form.per_page) !== 15));
const applyFilters = () => router.get(route("workflows.library"), form, { preserveState: true, preserveScroll: true, replace: true });
const resetFilters = () => {
    Object.assign(form, { search: "", status: "", project_id: "", creator_id: "", sort: "updated_at", direction: "desc", per_page: 15 });
    applyFilters();
};
const statusMeta = {
    draft: { label: "Brouillon", class: "bg-amber-50 text-amber-700 ring-amber-200" },
    active: { label: "Publié", class: "bg-emerald-50 text-emerald-700 ring-emerald-200" },
    archived: { label: "Archivé", class: "bg-slate-100 text-slate-600 ring-slate-200" },
};
const date = (value) => new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }).format(new Date(value));
</script>

<template>
    <Head title="Bibliothèque des Processus" />
    <AuthenticatedLayout>
        <div class="mx-auto max-w-[1600px] space-y-5 px-4 py-6 sm:px-7">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="relative px-5 py-6 sm:px-7">
                    <div class="absolute right-0 top-0 h-40 w-80 bg-gradient-to-bl from-blue-100/70 to-transparent" />
                    <div class="relative flex flex-wrap items-start justify-between gap-4">
                        <div><p class="text-xs font-black uppercase tracking-[.2em] text-blue-600">Pilotage des processus</p><h1 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Bibliothèque des Processus</h1><p class="mt-2 max-w-2xl text-sm text-slate-500">Consultez et retrouvez tous les circuits de vos projets depuis un seul espace.</p></div>
                        <div class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-right"><p class="text-2xl font-black text-blue-700">{{ summary.total }}</p><p class="text-[10px] font-bold uppercase tracking-wide text-blue-600">Processus au total</p></div>
                    </div>
                </div>
                <div class="grid border-t border-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="flex items-center gap-3 border-b border-slate-100 p-4 sm:border-r lg:border-b-0"><span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-600"><Workflow :size="19" /></span><div><b class="block text-xl text-slate-900">{{ summary.total }}</b><small class="text-slate-500">Workflows</small></div></div>
                    <div class="flex items-center gap-3 border-b border-slate-100 p-4 lg:border-b-0 lg:border-r"><span class="grid h-10 w-10 place-items-center rounded-xl bg-amber-50 text-amber-600"><CircleDot :size="19" /></span><div><b class="block text-xl text-slate-900">{{ summary.draft }}</b><small class="text-slate-500">Brouillons</small></div></div>
                    <div class="flex items-center gap-3 border-b border-slate-100 p-4 sm:border-r lg:border-b-0"><span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><CheckCircle2 :size="19" /></span><div><b class="block text-xl text-slate-900">{{ summary.active }}</b><small class="text-slate-500">Publiés</small></div></div>
                    <div class="flex items-center gap-3 p-4"><span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-50 text-violet-600"><Layers3 :size="19" /></span><div><b class="block text-xl text-slate-900">{{ summary.nodes }}</b><small class="text-slate-500">Étapes dessinées</small></div></div>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <form class="grid gap-3 border-b border-slate-200 p-4 md:grid-cols-2 xl:grid-cols-[minmax(260px,1.5fr)_1fr_1fr_1fr_auto]" @submit.prevent="applyFilters">
                    <label class="relative"><Search class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" :size="17" /><input v-model="form.search" class="h-11 w-full rounded-xl border-slate-200 pl-10 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Nom, projet ou code…" /></label>
                    <select v-model="form.project_id" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les projets</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option></select>
                    <select v-model="form.status" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les statuts</option><option value="draft">Brouillon</option><option value="active">Publié</option><option value="archived">Archivé</option></select>
                    <select v-model="form.creator_id" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les créateurs</option><option v-for="creator in creators" :key="creator.id" :value="creator.id">{{ creator.name }}</option></select>
                    <div class="flex gap-2"><button class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"><Filter :size="16" /> Filtrer</button><button v-if="hasFilters" type="button" class="grid h-11 w-11 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50" title="Réinitialiser" @click="resetFilters"><RotateCcw :size="16" /></button></div>
                    <div class="flex flex-wrap items-center gap-2 md:col-span-2 xl:col-span-5"><SlidersHorizontal :size="15" class="text-slate-400" /><span class="text-xs font-bold text-slate-500">Trier par</span><select v-model="form.sort" class="h-8 rounded-lg border-slate-200 py-1 text-xs" @change="applyFilters"><option value="updated_at">Dernière modification</option><option value="name">Nom</option><option value="project">Projet</option><option value="status">Statut</option><option value="nodes_count">Nombre d’étapes</option></select><select v-model="form.direction" class="h-8 rounded-lg border-slate-200 py-1 text-xs" @change="applyFilters"><option value="desc">Décroissant</option><option value="asc">Croissant</option></select><span class="ml-auto text-xs text-slate-400">{{ workflows.total }} résultat{{ workflows.total > 1 ? 's' : '' }}</span></div>
                </form>

                <div v-if="workflows.data.length" class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-[11px] font-black uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-3.5">Workflow</th><th class="px-4 py-3.5">Projet</th><th class="px-4 py-3.5">Créateur</th><th class="px-4 py-3.5 text-center">Structure</th><th class="px-4 py-3.5">Statut</th><th class="px-4 py-3.5">Modification</th><th class="px-5 py-3.5 text-right">Action</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="workflow in workflows.data" :key="workflow.id" class="group hover:bg-blue-50/30">
                                <td class="max-w-sm px-5 py-4"><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><GitBranch :size="18" /></span><div class="min-w-0"><p class="truncate font-bold text-slate-900">{{ workflow.name }}</p><p class="mt-0.5 truncate text-xs text-slate-400">{{ workflow.description || 'Aucune description' }}</p></div></div></td>
                                <td class="px-4 py-4"><p class="max-w-64 truncate font-semibold text-slate-700">{{ workflow.project.name }}</p><p class="mt-0.5 font-mono text-[10px] text-slate-400">{{ workflow.project.code || 'Sans code' }}</p></td>
                                <td class="px-4 py-4"><span class="inline-flex items-center gap-2 text-slate-600"><UserRound :size="15" class="text-slate-400" />{{ workflow.creator?.name || '—' }}</span></td>
                                <td class="px-4 py-4 text-center"><span class="font-bold text-blue-700">{{ workflow.nodes_count }}</span><small class="ml-1 text-slate-400">étapes</small><p class="text-[10px] text-slate-400">{{ workflow.edges_count }} liaisons</p></td>
                                <td class="px-4 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-black uppercase ring-1 ring-inset" :class="statusMeta[workflow.status]?.class">{{ statusMeta[workflow.status]?.label || workflow.status }}</span></td>
                                <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-500">{{ date(workflow.updated_at) }}</td>
                                <td class="px-5 py-4 text-right"><Link :href="route('workflows.edit', [workflow.project_id, workflow.id])" class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-bold text-blue-700 transition hover:bg-blue-600 hover:text-white"><Pencil :size="14" /> {{ workflow.status === 'draft' ? 'Modifier' : 'Consulter' }}</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="grid place-items-center px-6 py-20 text-center"><span class="grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400"><Workflow :size="28" /></span><h2 class="mt-4 font-black text-slate-800">Aucun workflow trouvé</h2><p class="mt-1 text-sm text-slate-400">Modifiez les filtres ou créez un workflow depuis un projet.</p><button v-if="hasFilters" class="mt-4 text-sm font-bold text-blue-600" @click="resetFilters">Réinitialiser les filtres</button></div>

                <footer v-if="workflows.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4"><p class="text-xs text-slate-500">{{ workflows.from }}–{{ workflows.to }} sur {{ workflows.total }}</p><div class="flex items-center gap-1"><Link v-for="link in workflows.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="grid min-h-9 min-w-9 place-items-center rounded-lg border px-2 text-xs font-bold" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50', !link.url ? 'pointer-events-none opacity-40' : '']"><ChevronLeft v-if="link.label.includes('Previous')" :size="15" /><ChevronRight v-else-if="link.label.includes('Next')" :size="15" /><span v-else v-html="link.label" /></Link></div></footer>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

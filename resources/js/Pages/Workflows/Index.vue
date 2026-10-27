<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useSweetAlert } from '@/Composables/useSweetAlert';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { Plus, Workflow, Trash2, ArrowRight, Copy, GitBranch, CheckCircle2, Search, X, MoreHorizontal, Pencil, Layers3, FilterX, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    workflows: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const { confirmAction } = useSweetAlert();
const form = useForm({ name: '', description: '' });
const isCreateOpen = ref(false);
const search = ref('');
const statusFilter = ref('all');
const activeMenuId = ref(null);
const busyId = ref(null);

const activeCount = computed(() => props.workflows.filter(w => w.status === 'active').length);
const draftCount = computed(() => props.workflows.filter(w => w.status !== 'active').length);
const nodeCount = computed(() => props.workflows.reduce((total, w) => total + (Number(w.nodes_count) || 0), 0));
const filteredWorkflows = computed(() => props.workflows.filter(w => {
    const q = search.value.trim().toLocaleLowerCase('fr');
    const matchesSearch = !q || `${w.name || ''} ${w.description || ''}`.toLocaleLowerCase('fr').includes(q);
    const matchesStatus = statusFilter.value === 'all' || (statusFilter.value === 'active' ? w.status === 'active' : w.status !== 'active');
    return matchesSearch && matchesStatus;
}));

const resetFilters = () => { search.value = ''; statusFilter.value = 'all'; };
const openCreate = () => { form.reset(); form.clearErrors(); isCreateOpen.value = true; };
const closeCreate = () => { if (form.processing) return; isCreateOpen.value = false; form.reset(); form.clearErrors(); };
const create = () => form.post(route('workflows.store', props.project.id), {
    preserveScroll: true,
    onSuccess: closeCreate,
});
const duplicate = id => {
    activeMenuId.value = null;
    busyId.value = id;
    router.post(route('workflows.duplicate', [props.project.id, id]), {}, {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
};
const remove = async w => {
    activeMenuId.value = null;
    if (!(await confirmAction({
        title: `Supprimer « ${w.name} » ?`,
        text: 'Ce workflow brouillon sera supprimé définitivement.',
        confirmButtonText: 'Supprimer le workflow',
    }))) return;
    busyId.value = w.id;
    router.delete(route('workflows.destroy', [props.project.id, w.id]), {
        preserveScroll: true,
        onFinish: () => { busyId.value = null; },
    });
};
</script>

<template>
    <Head :title="`Workflows · ${project.name}`" />
    <AuthenticatedLayout>
        <div class="min-w-0 max-w-full overflow-x-hidden bg-[#f1f3f6]">
            <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
                <header class="border border-slate-300 bg-[#f8f9fb] px-5 py-5 sm:px-7">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-[.15em] text-blue-700">Pilotage / Projets / Workflows</p>
                            <h1 class="mt-1 text-2xl font-bold text-slate-950">Workflows</h1>
                            <p class="mt-1 text-sm text-slate-600">Gestion des circuits et processus du projet <span class="font-semibold text-slate-800">{{ project.name }}</span></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Link :href="route('projects.index')" class="inline-flex items-center gap-2 rounded-[4px] border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                <ArrowRight class="h-4 w-4 rotate-180" /> Projets
                            </Link>
                            <button v-if="canManage" type="button" @click="openCreate" class="inline-flex items-center gap-2 rounded-[4px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                                <Plus class="h-4 w-4" /> Nouveau workflow
                            </button>
                        </div>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="border border-blue-200 bg-blue-50 px-4 py-3"><div class="flex items-center justify-between text-blue-700"><span class="text-[11px] font-bold uppercase tracking-wide">Total workflows</span><GitBranch class="h-5 w-5" /></div><strong class="mt-2 block text-2xl font-bold text-slate-950">{{ workflows.length }}</strong></div>
                        <div class="border border-emerald-200 bg-emerald-50 px-4 py-3"><div class="flex items-center justify-between text-emerald-700"><span class="text-[11px] font-bold uppercase tracking-wide">Publiés</span><CheckCircle2 class="h-5 w-5" /></div><strong class="mt-2 block text-2xl font-bold text-slate-950">{{ activeCount }}</strong></div>
                        <div class="border border-amber-200 bg-amber-50 px-4 py-3"><div class="flex items-center justify-between text-amber-700"><span class="text-[11px] font-bold uppercase tracking-wide">Brouillons</span><Pencil class="h-5 w-5" /></div><strong class="mt-2 block text-2xl font-bold text-slate-950">{{ draftCount }}</strong></div>
                        <div class="border border-violet-200 bg-violet-50 px-4 py-3"><div class="flex items-center justify-between text-violet-700"><span class="text-[11px] font-bold uppercase tracking-wide">Étapes dessinées</span><Layers3 class="h-5 w-5" /></div><strong class="mt-2 block text-2xl font-bold text-slate-950">{{ nodeCount }}</strong></div>
                    </div>
                </header>

                <section class="min-w-0 border border-slate-300 bg-[#f8f9fb]">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                        <div><h2 class="text-base font-bold text-slate-900">Liste des workflows</h2><p class="mt-0.5 text-xs text-slate-500">{{ filteredWorkflows.length }} résultat(s) sur {{ workflows.length }}</p></div>
                        <span class="inline-flex items-center gap-2 rounded-[4px] border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"><Workflow class="h-4 w-4" /> {{ project.name }}</span>
                    </div>
                    <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                        <label class="min-w-[220px] flex-1 text-xs font-semibold text-slate-600">Rechercher
                            <span class="relative mt-1 block"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><input v-model="search" type="search" placeholder="Nom ou description du workflow..." class="w-full rounded-[4px] border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm focus:border-blue-500 focus:ring-blue-500" /></span>
                        </label>
                        <label class="w-full text-xs font-semibold text-slate-600 sm:w-52">Statut
                            <select v-model="statusFilter" class="mt-1 w-full rounded-[4px] border border-slate-300 bg-white py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="all">Tous les statuts</option><option value="active">Publiés</option><option value="draft">Brouillons</option></select>
                        </label>
                        <button type="button" @click="resetFilters" class="inline-flex items-center justify-center gap-2 rounded-[4px] border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100"><FilterX class="h-4 w-4" /> Réinitialiser</button>
                    </div>

                    <div v-if="filteredWorkflows.length" class="max-w-full overflow-x-auto">
                        <table class="w-full min-w-[900px] table-fixed border-collapse text-left text-sm">
                            <colgroup><col class="w-[28%]" /><col class="w-[29%]" /><col class="w-[13%]" /><col class="w-[10%]" /><col class="w-[10%]" /><col class="w-[10%]" /></colgroup>
                            <thead class="bg-slate-100 text-[11px] font-bold uppercase tracking-wide text-slate-600"><tr><th class="border-b border-r border-slate-200 px-4 py-3">Workflow</th><th class="border-b border-r border-slate-200 px-4 py-3">Description</th><th class="border-b border-r border-slate-200 px-4 py-3">Statut</th><th class="border-b border-r border-slate-200 px-4 py-3 text-center">Étapes</th><th class="border-b border-r border-slate-200 px-4 py-3 text-center">Liaisons</th><th class="border-b border-slate-200 px-4 py-3 text-right">Gestion</th></tr></thead>
                            <tbody class="bg-white">
                                <tr v-for="w in filteredWorkflows" :key="w.id" class="hover:bg-blue-50/40">
                                    <td class="border-b border-r border-slate-200 px-4 py-4"><div class="flex min-w-0 items-center gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[4px] bg-blue-50 text-blue-700"><Workflow class="h-5 w-5" /></span><div class="min-w-0"><p class="truncate font-bold text-slate-900" :title="w.name">{{ w.name }}</p><p class="mt-0.5 text-[11px] text-slate-500">Circuit de validation</p></div></div></td>
                                    <td class="border-b border-r border-slate-200 px-4 py-4"><p class="line-clamp-2 text-slate-600" :title="w.description || ''">{{ w.description || 'Aucune description renseignée' }}</p></td>
                                    <td class="border-b border-r border-slate-200 px-4 py-4"><span class="inline-flex items-center gap-1.5 rounded-[4px] border px-2.5 py-1 text-xs font-bold" :class="w.status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700'"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ w.status === 'active' ? 'Publié' : 'Brouillon' }}</span></td>
                                    <td class="border-b border-r border-slate-200 px-4 py-4 text-center font-bold text-slate-700">{{ w.nodes_count ?? 0 }}</td>
                                    <td class="border-b border-r border-slate-200 px-4 py-4 text-center font-bold text-slate-700">{{ w.edges_count ?? 0 }}</td>
                                    <td class="border-b border-slate-200 px-4 py-4"><div class="flex items-center justify-end gap-1.5"><Link :href="route('workflows.edit', [project.id, w.id])" title="Ouvrir l'éditeur" class="inline-flex h-9 w-9 items-center justify-center rounded-[4px] border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100"><ArrowRight class="h-4 w-4" /></Link><div v-if="canManage" class="relative"><button type="button" :disabled="busyId === w.id" :aria-label="`Actions de ${w.name}`" :aria-expanded="activeMenuId === w.id" @click="activeMenuId = activeMenuId === w.id ? null : w.id" class="inline-flex h-9 w-9 items-center justify-center rounded-[4px] border border-slate-200 bg-white text-slate-600 hover:bg-slate-100"><LoaderCircle v-if="busyId === w.id" class="h-4 w-4 animate-spin" /><MoreHorizontal v-else class="h-4 w-4" /></button><div v-if="activeMenuId === w.id" class="absolute right-0 top-10 z-20 w-44 overflow-hidden rounded-[4px] border border-slate-200 bg-white py-1 shadow-xl"><button type="button" @click="duplicate(w.id)" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-blue-50"><Copy class="h-4 w-4 text-blue-600" /> Dupliquer</button><button v-if="w.status === 'draft'" type="button" @click="remove(w)" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-rose-700 hover:bg-rose-50"><Trash2 class="h-4 w-4" /> Supprimer</button></div></div></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="grid place-items-center gap-3 px-6 py-16 text-center"><div class="flex h-14 w-14 items-center justify-center rounded-[4px] bg-blue-50 text-blue-600"><Workflow class="h-7 w-7" /></div><div><h3 class="font-bold text-slate-900">{{ workflows.length ? 'Aucun workflow trouvé' : 'Aucun workflow pour ce projet' }}</h3><p class="mt-1 text-sm text-slate-500">{{ workflows.length ? 'Modifiez les filtres pour afficher des résultats.' : 'Créez votre premier circuit de validation.' }}</p></div><button v-if="canManage && !workflows.length" type="button" @click="openCreate" class="inline-flex items-center gap-2 rounded-[4px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"><Plus class="h-4 w-4" /> Créer un workflow</button><button v-else-if="workflows.length" type="button" @click="resetFilters" class="text-sm font-semibold text-blue-700 hover:underline">Effacer les filtres</button></div>
                </section>
            </div>
        </div>

        <Modal :show="isCreateOpen" max-width="2xl" @close="closeCreate">
            <div class="flex max-h-[85vh] min-h-0 flex-col bg-white">
                <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-6"><div><p class="text-[11px] font-bold uppercase tracking-widest text-blue-700">{{ project.name }}</p><h2 class="mt-1 text-xl font-bold text-slate-900">Nouveau workflow</h2><p class="mt-1 text-xs text-slate-500">Créez un circuit personnalisable pour ce projet.</p></div><button type="button" @click="closeCreate" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[4px] border border-slate-200 text-slate-500 hover:bg-rose-50 hover:text-rose-600" aria-label="Fermer"><X class="h-4 w-4" /></button></header>
                <form id="create-workflow-form" @submit.prevent="create" class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-6"><div><label for="workflow-name" class="mb-1.5 block text-xs font-bold text-slate-700">Nom du workflow <span class="text-rose-600">*</span></label><input id="workflow-name" v-model="form.name" required maxlength="150" placeholder="Ex. Validation des livrables" class="w-full rounded-[4px] border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" /><p v-if="form.errors.name" class="mt-1 text-xs text-rose-600">{{ form.errors.name }}</p></div><div><label for="workflow-description" class="mb-1.5 block text-xs font-bold text-slate-700">Description</label><textarea id="workflow-description" v-model="form.description" rows="4" placeholder="Décrivez les objectifs et les étapes du circuit..." class="w-full resize-y rounded-[4px] border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" /><p v-if="form.errors.description" class="mt-1 text-xs text-rose-600">{{ form.errors.description }}</p></div></form>
                <footer class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6"><button type="button" :disabled="form.processing" @click="closeCreate" class="rounded-[4px] border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Annuler</button><button type="submit" form="create-workflow-form" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-[4px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"><LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" /><Plus v-else class="h-4 w-4" /> Créer le workflow</button></footer>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

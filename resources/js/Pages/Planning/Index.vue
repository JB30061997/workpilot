<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useSweetAlert } from '@/Composables/useSweetAlert';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    plannings: { type: Object, required: true },
    tasks: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    members: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const { confirmAction, notify } = useSweetAlert();
const filterForm = ref({
    search: props.filters.search ?? '', project_id: props.filters.project_id ?? '',
    assignee_id: props.filters.assignee_id ?? '', status_id: props.filters.status_id ?? '',
    from: props.filters.from ?? '', to: props.filters.to ?? '',
    sort: props.filters.sort ?? 'start_at', direction: props.filters.direction ?? 'asc',
    per_page: props.filters.per_page ?? 25,
});
const modalMode = ref(null);
const selectedPlanning = ref(null);
const taskSearch = ref('');
const form = useForm({ task_id: '', start_at: '', end_at: '' });

const filteredTasks = computed(() => {
    const search = taskSearch.value.trim().toLocaleLowerCase('fr');
    if (!search) return props.tasks;
    return props.tasks.filter((task) => [task.title, task.project?.name, task.project?.action_plan?.name]
        .filter(Boolean).some((value) => value.toLocaleLowerCase('fr').includes(search)));
});
const selectedTask = computed(() => props.tasks.find((task) => Number(task.id) === Number(form.task_id)) ?? null);

const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR').format(new Date(value)) : '—';
const formatTime = (value) => value ? new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
const toLocalInput = (value) => value ? new Date(new Date(value).getTime() - new Date(value).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '';
const duration = (planning) => {
    const minutes = Math.max(0, Math.round((new Date(planning.end_at) - new Date(planning.start_at)) / 60000));
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);
    const rest = hours % 24;
    return days ? `${days} j${rest ? ` ${rest} h` : ''}` : `${hours} h${minutes % 60 ? ` ${minutes % 60} min` : ''}`;
};
const initials = (name) => name?.split(' ').map((word) => word[0]).join('').slice(0, 2).toUpperCase() || '—';
const priorityLabel = { low: 'Basse', medium: 'Normale', high: 'Haute', critical: 'Urgente' };
const priorityClass = { low: 'bg-slate-100 text-slate-600', medium: 'bg-blue-50 text-blue-700', high: 'bg-amber-50 text-amber-700', critical: 'bg-rose-50 text-rose-700' };

const applyFilters = () => router.get(route('planning.index'), filterForm.value, { preserveState: true, replace: true });
const resetFilters = () => {
    filterForm.value = { search: '', project_id: '', assignee_id: '', status_id: '', from: '', to: '', sort: 'start_at', direction: 'asc', per_page: 25 };
    applyFilters();
};
const sortBy = (column) => {
    filterForm.value.direction = filterForm.value.sort === column && filterForm.value.direction === 'asc' ? 'desc' : 'asc';
    filterForm.value.sort = column;
    applyFilters();
};
const openCreate = () => { modalMode.value = 'create'; selectedPlanning.value = null; taskSearch.value = ''; form.reset(); form.clearErrors(); };
const openView = (planning) => { selectedPlanning.value = planning; modalMode.value = 'view'; };
const openEdit = (planning) => {
    selectedPlanning.value = planning; modalMode.value = 'edit'; form.clearErrors();
    form.task_id = planning.task_id; form.start_at = toLocalInput(planning.start_at); form.end_at = toLocalInput(planning.end_at);
};
const openDuplicate = (planning) => {
    selectedPlanning.value = planning; modalMode.value = 'duplicate'; form.clearErrors();
    form.task_id = planning.task_id; form.start_at = toLocalInput(planning.start_at); form.end_at = toLocalInput(planning.end_at);
};
const closeModal = () => { modalMode.value = null; selectedPlanning.value = null; form.clearErrors(); };
const submit = () => {
    const currentMode = modalMode.value;
    const options = { preserveScroll: true, onSuccess: () => { closeModal(); notify(currentMode === 'edit' ? 'Planification modifiée.' : currentMode === 'duplicate' ? 'Planification dupliquée.' : 'Tâche ajoutée au planning.'); } };
    if (modalMode.value === 'edit') form.put(route('planning.update', selectedPlanning.value.id), options);
    else if (modalMode.value === 'duplicate') form.post(route('planning.duplicate', selectedPlanning.value.id), options);
    else form.post(route('planning.store'), options);
};
const removePlanning = async (planning) => {
    if (!await confirmAction({ title: 'Retirer cette tâche du planning ?', text: 'La tâche originale sera conservée.', confirmButtonText: 'Retirer du planning' })) return;
    router.delete(route('planning.destroy', planning.id), { preserveScroll: true, onSuccess: () => notify('Tâche retirée du planning.') });
};
</script>

<template>
    <Head title="Planning des tâches" />
    <AuthenticatedLayout>
        <div class="mx-auto grid w-full min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
            <header class="wp-page-header flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-7">
                <div><p class="text-[11px] font-bold uppercase tracking-[.15em] text-blue-700">Organisation</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Planning des tâches</h1><p class="mt-1 text-sm text-slate-500">Organisez et suivez les tâches de votre équipe.</p></div>
                <button type="button" class="inline-flex h-10 items-center justify-center gap-2 rounded-[4px] bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700" @click="openCreate"><span class="text-lg">＋</span> Ajouter au planning</button>
            </header>

            <form class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" @submit.prevent="applyFilters">
                <div class="grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-[minmax(280px,1.45fr)_repeat(3,minmax(170px,1fr))]">
                    <label class="relative min-w-0"><span class="sr-only">Rechercher une tâche</span><svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input v-model="filterForm.search" type="search" placeholder="Rechercher une tâche…" class="h-11 w-full rounded-xl border-slate-200 pl-10 text-sm focus:border-blue-500 focus:ring-blue-500" /></label>
                    <select v-model="filterForm.project_id" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les projets</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option></select>
                    <select v-model="filterForm.assignee_id" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les responsables</option><option v-for="member in members" :key="member.id" :value="member.id">{{ member.name }}</option></select>
                    <select v-model="filterForm.status_id" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les statuts</option><option v-for="status in statuses" :key="status.id" :value="status.id">{{ status.name }}</option></select>
                </div>
                <div class="mt-3 grid min-w-0 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-[minmax(180px,240px)_minmax(180px,240px)_auto] lg:items-end">
                    <label class="grid min-w-0 gap-1.5"><span class="text-xs font-bold text-slate-500">Date de début</span><input v-model="filterForm.from" type="date" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /></label>
                    <label class="grid min-w-0 gap-1.5"><span class="text-xs font-bold text-slate-500">Date de fin</span><input v-model="filterForm.to" type="date" class="h-11 min-w-0 rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /></label>
                    <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-1"><button class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-500 lg:flex-none"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 5h16M7 12h10M10 19h4"/></svg>Filtrer</button><button type="button" class="h-11 flex-1 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 lg:flex-none" @click="resetFilters">Réinitialiser</button></div>
                </div>
            </form>

            <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4"><div><h2 class="font-black text-slate-900">Tâches planifiées</h2><p class="mt-0.5 text-xs text-slate-500">{{ plannings.total }} entrée{{ plannings.total > 1 ? 's' : '' }} au total</p></div><select v-model="filterForm.per_page" class="rounded-lg border-slate-200 py-1.5 text-xs" @change="applyFilters"><option :value="10">10 lignes</option><option :value="25">25 lignes</option><option :value="50">50 lignes</option><option :value="100">100 lignes</option></select></div>
                <div class="hidden max-h-[62vh] overflow-auto md:block">
                    <table class="w-full min-w-[980px] table-fixed border-separate border-spacing-0 text-left text-sm">
                        <thead class="sticky top-0 z-10 bg-blue-50/95 text-[11px] font-black uppercase tracking-wide text-slate-600 backdrop-blur">
                            <tr><th class="w-[19%] border-b border-slate-200 px-4 py-3">Tâche</th><th class="w-[17%] border-b border-slate-200 px-4 py-3">Projet / Plan</th><th class="w-[11%] border-b border-slate-200 px-4 py-3">Responsable</th><th class="w-[12%] border-b border-slate-200 px-4 py-3"><button type="button" class="hover:text-blue-700" @click="sortBy('start_at')">Début prévu ↕</button></th><th class="w-[12%] border-b border-slate-200 px-4 py-3"><button type="button" class="hover:text-blue-700" @click="sortBy('end_at')">Fin prévue ↕</button></th><th class="w-[8%] border-b border-slate-200 px-4 py-3">Durée</th><th class="w-[9%] border-b border-slate-200 px-4 py-3">Statut</th><th class="w-[8%] border-b border-slate-200 px-4 py-3">Progression</th><th class="sticky right-0 w-[116px] border-b border-l border-slate-200 bg-blue-50 px-3 py-3 text-right">Actions</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="planning in plannings.data" :key="planning.id" class="group h-[68px] transition hover:bg-blue-50/40">
                                <td class="min-w-0 border-b border-slate-100 px-4 py-3"><div class="min-w-0"><span class="block truncate font-bold text-slate-900" :title="planning.task.title">{{ planning.task.title }}</span><span class="mt-1 inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold" :class="priorityClass[planning.task.priority]">{{ priorityLabel[planning.task.priority] ?? '—' }}</span></div></td>
                                <td class="min-w-0 border-b border-slate-100 px-4 py-3"><span class="block truncate font-semibold text-slate-700" :title="planning.task.project?.name">{{ planning.task.project?.name ?? '—' }}</span><span class="mt-1 block truncate text-xs text-slate-400" :title="planning.task.project?.action_plan?.name">{{ planning.task.project?.action_plan?.name ?? 'Sans plan d’action' }}</span></td>
                                <td class="border-b border-slate-100 px-4 py-3"><div v-if="planning.task.assignees?.length" class="flex items-center"><span v-for="person in planning.task.assignees.slice(0, 2)" :key="person.id" :title="person.name" class="-ml-1 grid h-7 w-7 place-items-center rounded-full border-2 border-white bg-blue-100 text-[9px] font-black text-blue-700 first:ml-0">{{ initials(person.name) }}</span><span v-if="planning.task.assignees.length > 2" class="ml-1 text-xs text-slate-500">+{{ planning.task.assignees.length - 2 }}</span></div><span v-else class="text-slate-400">—</span></td>
                                <td class="border-b border-slate-100 px-4 py-3"><span class="block font-semibold tabular-nums text-slate-700">{{ formatDate(planning.start_at) }}</span><span class="mt-1 block text-xs tabular-nums text-slate-400">{{ formatTime(planning.start_at) }}</span></td>
                                <td class="border-b border-slate-100 px-4 py-3"><span class="block font-semibold tabular-nums text-slate-700">{{ formatDate(planning.end_at) }}</span><span class="mt-1 block text-xs tabular-nums text-slate-400">{{ formatTime(planning.end_at) }}</span></td>
                                <td class="border-b border-slate-100 px-4 py-3 font-bold text-slate-700">{{ duration(planning) }}</td><td class="border-b border-slate-100 px-4 py-3"><span class="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">{{ planning.task.status?.name ?? '—' }}</span></td>
                                <td class="border-b border-slate-100 px-4 py-3"><span class="text-xs font-bold tabular-nums text-slate-600">{{ planning.task.progress ?? 0 }}%</span><div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${planning.task.progress ?? 0}%` }" /></div></td>
                                <td class="sticky right-0 border-b border-l border-slate-100 bg-white px-2 py-2 group-hover:bg-[#f8fbff]"><div class="flex justify-end gap-0.5"><button type="button" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 hover:bg-blue-50 hover:text-blue-600" title="Consulter" @click="openView(planning)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button><button type="button" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 hover:bg-blue-50 hover:text-blue-600" title="Modifier" @click="openEdit(planning)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Z"/></svg></button><button type="button" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 hover:bg-blue-50 hover:text-blue-600" title="Dupliquer" @click="openDuplicate(planning)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg></button><button type="button" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Retirer" @click="removePlanning(planning)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13"/></svg></button></div></td>
                            </tr>
                            <tr v-if="!plannings.data.length"><td colspan="9" class="h-[240px] px-6 text-center"><span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"><AppIcon name="calendar" class="h-6 w-6" /></span><p class="mt-4 font-black text-slate-800">Aucune tâche planifiée</p><p class="mt-1 text-sm text-slate-500">Commencez par ajouter une tâche à votre planning.</p><button type="button" class="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-500" @click="openCreate">＋ Ajouter au planning</button></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-3 bg-slate-50/60 p-3 md:hidden">
                    <article v-for="planning in plannings.data" :key="planning.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="truncate font-black text-slate-900" :title="planning.task.title">{{ planning.task.title }}</h3><p class="mt-1 truncate text-xs text-slate-500">{{ planning.task.project?.name ?? 'Sans projet' }} · {{ planning.task.project?.action_plan?.name ?? 'Sans plan' }}</p></div><span class="shrink-0 rounded-md px-2 py-1 text-[10px] font-bold" :class="priorityClass[planning.task.priority]">{{ priorityLabel[planning.task.priority] ?? '—' }}</span></div><div class="mt-4 grid grid-cols-2 gap-3 text-xs"><div><p class="font-bold text-slate-400">Début prévu</p><p class="mt-1 font-semibold text-slate-700">{{ formatDate(planning.start_at) }} · {{ formatTime(planning.start_at) }}</p></div><div><p class="font-bold text-slate-400">Fin prévue</p><p class="mt-1 font-semibold text-slate-700">{{ formatDate(planning.end_at) }} · {{ formatTime(planning.end_at) }}</p></div><div><p class="font-bold text-slate-400">Durée</p><p class="mt-1 font-semibold text-slate-700">{{ duration(planning) }}</p></div><div><p class="font-bold text-slate-400">Statut</p><p class="mt-1 font-semibold text-blue-700">{{ planning.task.status?.name ?? '—' }}</p></div></div><div class="mt-4"><div class="flex justify-between text-xs font-bold text-slate-500"><span>Progression</span><span>{{ planning.task.progress ?? 0 }}%</span></div><div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${planning.task.progress ?? 0}%` }" /></div></div><div class="mt-4 flex justify-end gap-1 border-t border-slate-100 pt-3"><button type="button" class="rounded-lg px-3 py-2 text-xs font-bold text-slate-500 hover:bg-blue-50 hover:text-blue-600" @click="openView(planning)">Consulter</button><button type="button" class="rounded-lg px-3 py-2 text-xs font-bold text-slate-500 hover:bg-blue-50 hover:text-blue-600" @click="openEdit(planning)">Modifier</button><button type="button" class="rounded-lg px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50" @click="removePlanning(planning)">Retirer</button></div></article>
                    <div v-if="!plannings.data.length" class="grid h-[240px] place-items-center rounded-2xl border border-dashed border-slate-200 bg-white px-6 text-center"><div><span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"><AppIcon name="calendar" class="h-6 w-6" /></span><p class="mt-4 font-black text-slate-800">Aucune tâche planifiée</p><p class="mt-1 text-sm text-slate-500">Commencez par ajouter une tâche à votre planning.</p><button type="button" class="mt-4 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white" @click="openCreate">＋ Ajouter au planning</button></div></div>
                </div>
                <div v-if="plannings.links?.length > 3" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4"><p class="text-xs text-slate-500">{{ plannings.from }}–{{ plannings.to }} sur {{ plannings.total }}</p><div class="flex gap-1"><Link v-for="link in plannings.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border px-3 py-1.5 text-xs" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 text-slate-600', !link.url ? 'pointer-events-none opacity-40' : 'hover:bg-slate-50']" v-html="link.label" /></div></div>
            </section>
        </div>

        <Modal :show="['create', 'edit', 'duplicate'].includes(modalMode)" max-width="2xl" @close="closeModal">
            <form class="p-6 sm:p-8" @submit.prevent="submit">
                <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-wider text-blue-600">Planning</p><h2 class="mt-1 text-xl font-black text-slate-950">{{ modalMode === 'edit' ? 'Modifier la période' : modalMode === 'duplicate' ? 'Dupliquer la planification' : 'Ajouter au planning' }}</h2></div><button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="closeModal">✕</button></div>
                <div class="mt-6 space-y-5">
                    <div v-if="modalMode === 'create'"><label class="text-sm font-bold text-slate-700">Sélectionner une tâche</label><input v-model="taskSearch" type="search" placeholder="Rechercher par tâche, projet ou plan…" class="mt-2 w-full rounded-xl border-slate-200 text-sm" /><div class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-slate-200"><button v-for="task in filteredTasks" :key="task.id" type="button" class="flex w-full items-start justify-between gap-4 border-b border-slate-100 px-4 py-3 text-left last:border-0 hover:bg-blue-50" :class="Number(form.task_id) === task.id ? 'bg-blue-50 ring-1 ring-inset ring-blue-200' : ''" @click="form.task_id = task.id"><span><span class="block font-bold text-slate-900">{{ task.title }}</span><span class="mt-1 block text-xs text-slate-500">{{ task.project?.name ?? 'Sans projet' }} · {{ task.project?.action_plan?.name ?? 'Sans plan d’action' }}</span></span><span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">{{ task.status?.name ?? '—' }}</span></button><p v-if="!filteredTasks.length" class="p-5 text-center text-sm text-slate-500">Aucune tâche accessible.</p></div><InputError class="mt-2" :message="form.errors.task_id" /></div>
                    <div v-if="selectedTask || selectedPlanning" class="rounded-xl border border-blue-100 bg-blue-50/60 p-4"><p class="font-black text-slate-900">{{ selectedTask?.title ?? selectedPlanning?.task?.title }}</p><div class="mt-2 grid gap-2 text-xs text-slate-600 sm:grid-cols-3"><span>Projet : <b>{{ (selectedTask ?? selectedPlanning.task).project?.name ?? '—' }}</b></span><span>Statut : <b>{{ (selectedTask ?? selectedPlanning.task).status?.name ?? '—' }}</b></span><span>Progression : <b>{{ (selectedTask ?? selectedPlanning.task).progress ?? 0 }}%</b></span></div></div>
                    <div class="grid gap-4 sm:grid-cols-2"><label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Début prévu</span><input v-model="form.start_at" type="datetime-local" required class="rounded-xl border-slate-200" /><InputError :message="form.errors.start_at" /></label><label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Fin prévue</span><input v-model="form.end_at" type="datetime-local" required class="rounded-xl border-slate-200" /><InputError :message="form.errors.end_at" /></label></div>
                </div>
                <div class="mt-7 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600" @click="closeModal">Annuler</button><button :disabled="form.processing || !form.task_id" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white disabled:opacity-50">{{ form.processing ? 'Enregistrement…' : 'Enregistrer' }}</button></div>
            </form>
        </Modal>

        <Modal :show="modalMode === 'view'" max-width="2xl" @close="closeModal"><div v-if="selectedPlanning" class="p-6 sm:p-8"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-wider text-blue-600">Détail de la planification</p><h2 class="mt-1 text-xl font-black text-slate-950">{{ selectedPlanning.task.title }}</h2></div><button class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="closeModal">✕</button></div><dl class="mt-6 grid gap-4 rounded-2xl bg-slate-50 p-5 sm:grid-cols-2"><div><dt class="text-xs font-bold text-slate-400">Projet</dt><dd class="mt-1 font-bold text-slate-800">{{ selectedPlanning.task.project?.name ?? '—' }}</dd></div><div><dt class="text-xs font-bold text-slate-400">Plan d’action</dt><dd class="mt-1 font-bold text-slate-800">{{ selectedPlanning.task.project?.action_plan?.name ?? '—' }}</dd></div><div><dt class="text-xs font-bold text-slate-400">Début</dt><dd class="mt-1 font-bold text-slate-800">{{ formatDate(selectedPlanning.start_at) }} à {{ formatTime(selectedPlanning.start_at) }}</dd></div><div><dt class="text-xs font-bold text-slate-400">Fin</dt><dd class="mt-1 font-bold text-slate-800">{{ formatDate(selectedPlanning.end_at) }} à {{ formatTime(selectedPlanning.end_at) }}</dd></div><div><dt class="text-xs font-bold text-slate-400">Durée</dt><dd class="mt-1 font-bold text-slate-800">{{ duration(selectedPlanning) }}</dd></div><div><dt class="text-xs font-bold text-slate-400">Responsable</dt><dd class="mt-1 font-bold text-slate-800">{{ selectedPlanning.task.assignees?.map((person) => person.name).join(', ') || '—' }}</dd></div></dl><div class="mt-6 flex justify-end"><button class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white" @click="openEdit(selectedPlanning)">Modifier la période</button></div></div></Modal>
    </AuthenticatedLayout>
</template>

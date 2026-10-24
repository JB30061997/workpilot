<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    work_session: Object,
    members: Array,
    task_statuses: Array,
});

const activeTab = ref('tasks');
const isTaskFormOpen = ref(false);
const isMemberFormOpen = ref(false);

const taskForm = useForm({
    work_session_id: props.work_session.id,
    title: '',
    description: '',
    priority: 'medium',
    status_id: props.task_statuses.find((status) => status.is_default)?.id ?? props.task_statuses[0]?.id ?? '',
    assignee_ids: [],
    start_at: '',
    due_at: '',
});

const memberForm = useForm({
    work_session_id: props.work_session.id,
    user_id: '',
    role: 'member',
});

const statusLabels = { draft: 'Brouillon', active: 'En cours', completed: 'Terminée', archived: 'Archivée' };
const visibilityLabels = { private: 'Privée', department: 'Département' };
const priorityLabels = { low: 'Basse', medium: 'Moyenne', high: 'Haute', critical: 'Critique' };
const priorityClasses = {
    low: 'bg-slate-100 text-slate-600',
    medium: 'bg-blue-50 text-blue-700',
    high: 'bg-amber-50 text-amber-700',
    critical: 'bg-rose-50 text-rose-700',
};

const availableMembers = computed(() => {
    const selectedIds = new Set(props.work_session.members.map((member) => Number(member.id)));
    return props.members.filter((member) => !selectedIds.has(Number(member.id)));
});

const progress = computed(() => {
    if (!props.work_session.tasks.length) return 0;
    return Math.round(props.work_session.tasks.reduce((sum, task) => sum + Number(task.progress), 0) / props.work_session.tasks.length);
});

const formatDate = (value, options = {}) => value
    ? new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'long', ...options }).format(new Date(`${value.slice(0, 10)}T12:00:00`))
    : '—';

const initials = (name) => name?.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase() ?? '?';

const submitTask = () => taskForm.post(route('tasks.store'), {
    preserveScroll: true,
    onSuccess: () => {
        isTaskFormOpen.value = false;
        taskForm.reset();
        taskForm.work_session_id = props.work_session.id;
        taskForm.status_id = props.task_statuses.find((status) => status.is_default)?.id ?? props.task_statuses[0]?.id ?? '';
    },
});

const submitMember = () => memberForm.post(route('session-members.store'), {
    preserveScroll: true,
    onSuccess: () => {
        isMemberFormOpen.value = false;
        memberForm.reset();
        memberForm.work_session_id = props.work_session.id;
        memberForm.role = 'member';
    },
});

const updateTaskStatus = (task, statusId) => router.patch(route('tasks.update', task.id), {
    status_id: Number(statusId),
}, { preserveScroll: true });
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`${work_session.name} - WorkPilot`" />

        <div class="min-h-full min-w-0 max-w-full overflow-x-hidden bg-[#f1f3f6] px-3 py-5 sm:px-5 lg:px-7">
            <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4">
                <Link :href="route('work-sessions.index')" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 transition hover:text-blue-600">← Toutes les sessions</Link>

                <header class="wp-page-header relative overflow-hidden px-5 py-5 sm:px-7">
                    <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full bg-blue-500/20 blur-3xl" />
                    <div class="relative flex flex-col justify-between gap-8 lg:flex-row lg:items-end">
                        <div class="max-w-3xl">
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full border border-blue-200 bg-white/80 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-slate-600">{{ visibilityLabels[work_session.visibility] }}</span>
                                <span class="rounded-full bg-blue-100 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-blue-700">● {{ statusLabels[work_session.status] }}</span>
                            </div>
                            <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-950">{{ work_session.name }}</h1>
                            <p class="mt-3 text-sm font-semibold text-blue-700 sm:text-base">{{ formatDate(work_session.start_date) }} → {{ formatDate(work_session.end_date, { year: 'numeric' }) }}</p>
                            <p v-if="work_session.description" class="mt-4 max-w-2xl text-sm leading-6 text-slate-600">{{ work_session.description }}</p>
                        </div>
                        <div class="min-w-64 rounded-2xl border border-blue-100 bg-white/75 p-4 shadow-sm backdrop-blur">
                            <div class="flex items-center justify-between text-xs font-bold text-slate-700"><span>Progression</span><span>{{ progress }}%</span></div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100"><div class="h-full rounded-full bg-blue-600 transition-all duration-700" :style="{ width: `${progress}%` }" /></div>
                            <p class="mt-3 text-xs text-slate-500">{{ work_session.tasks.length }} tâches · {{ work_session.time_slots_count }} créneaux</p>
                        </div>
                    </div>
                </header>

                <section class="flex flex-col justify-between gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:p-5">
                    <div class="flex items-center">
                        <div class="flex -space-x-2">
                            <span v-for="member in work_session.members.slice(0, 7)" :key="member.id" :title="member.name" class="grid h-10 w-10 place-items-center rounded-full border-2 border-white bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-black text-white shadow-sm">{{ initials(member.name) }}</span>
                        </div>
                        <div class="ml-4"><p class="text-sm font-black text-slate-900">{{ work_session.members.length }} membres</p><p class="text-xs text-slate-500">{{ work_session.members.map((member) => member.name).join(' · ') }}</p></div>
                    </div>
                    <button type="button" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-2.5 text-sm font-bold text-blue-700 transition hover:border-blue-200 hover:bg-blue-100" @click="isMemberFormOpen = true">+ Ajouter un membre</button>
                </section>

                <nav class="flex gap-1 overflow-x-auto rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-sm">
                    <button v-for="tab in [{ id: 'overview', label: 'Vue d’ensemble' }, { id: 'tasks', label: 'Tâches' }, { id: 'planning', label: 'Planning' }, { id: 'activity', label: 'Activité' }]" :key="tab.id" type="button" class="whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-bold transition" :class="activeTab === tab.id ? 'bg-blue-50 text-blue-700 shadow-sm' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'" @click="activeTab = tab.id">{{ tab.label }}</button>
                </nav>

                <section v-if="activeTab === 'tasks' || activeTab === 'overview'" class="space-y-4">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div><p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Espace de travail</p><h2 class="mt-1 text-2xl font-black text-slate-950">Tâches de la session</h2></div>
                        <button type="button" class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-200 transition hover:-translate-y-0.5 hover:bg-blue-500" @click="isTaskFormOpen = true">+ Nouvelle tâche</button>
                    </div>

                    <div v-if="work_session.tasks.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="task in work_session.tasks" :key="task.id" class="group rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-100/60">
                            <div class="flex items-start justify-between gap-3"><span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wide" :class="priorityClasses[task.priority]">{{ priorityLabels[task.priority] }}</span><span class="text-xs font-black text-slate-400">{{ task.progress }}%</span></div>
                            <h3 class="mt-4 text-base font-black text-slate-900">{{ task.title }}</h3>
                            <p v-if="task.description" class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ task.description }}</p>
                            <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500" :style="{ width: `${task.progress}%` }" /></div>
                            <div class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                                <div class="flex -space-x-1.5"><span v-for="assignee in task.assignees" :key="assignee.id" :title="assignee.name" class="grid h-7 w-7 place-items-center rounded-full border-2 border-white bg-slate-800 text-[9px] font-black text-white">{{ initials(assignee.name) }}</span><span v-if="!task.assignees.length" class="text-xs text-slate-400">Non affectée</span></div>
                                <select :value="task.status_id" class="max-w-36 rounded-lg border-slate-200 py-1.5 text-xs font-bold text-slate-600 focus:border-blue-500 focus:ring-blue-500" @change="updateTaskStatus(task, $event.target.value)"><option v-for="status in task_statuses" :key="status.id" :value="status.id">{{ status.name }}</option></select>
                            </div>
                        </article>
                    </div>
                    <div v-else class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center"><div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-600">✓</div><h3 class="mt-4 text-lg font-black text-slate-900">Aucune tâche</h3><p class="mt-2 text-sm text-slate-500">Créez la première tâche de cette session.</p></div>
                </section>

                <section v-else-if="activeTab === 'planning'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <article v-for="slot in work_session.time_slots" :key="slot.id" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-black uppercase tracking-wider text-blue-600">{{ slot.status }}</p><h3 class="mt-2 font-black text-slate-900">{{ slot.task?.title ?? slot.title }}</h3><p class="mt-2 text-sm text-slate-500">{{ new Date(slot.start_at).toLocaleString('fr-FR') }} → {{ new Date(slot.end_at).toLocaleString('fr-FR') }}</p></article>
                    <p v-if="!work_session.time_slots.length" class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">Aucun créneau planifié.</p>
                </section>

                <section v-else class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center"><p class="font-black text-slate-900">Activité de la session</p><p class="mt-2 text-sm text-slate-500">Les changements de tâches et de planning apparaîtront ici.</p></section>
            </div>
        </div>

        <Modal :show="isTaskFormOpen" max-width="2xl" @close="isTaskFormOpen = false">
            <form class="p-6 sm:p-8" @submit.prevent="submitTask">
                <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-wider text-blue-600">{{ work_session.name }}</p><h3 class="mt-1 text-xl font-black text-slate-950">Nouvelle tâche</h3></div><button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="isTaskFormOpen = false">✕</button></div>
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1.5 sm:col-span-2"><span class="text-sm font-bold text-slate-700">Titre</span><input v-model="taskForm.title" required class="rounded-xl border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="taskForm.errors.title" /></label>
                    <label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Statut</span><select v-model="taskForm.status_id" class="rounded-xl border-slate-200"><option v-for="status in task_statuses" :key="status.id" :value="status.id">{{ status.name }}</option></select><InputError :message="taskForm.errors.status_id" /></label>
                    <label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Priorité</span><select v-model="taskForm.priority" class="rounded-xl border-slate-200"><option v-for="(label, value) in priorityLabels" :key="value" :value="value">{{ label }}</option></select></label>
                    <label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Début</span><input v-model="taskForm.start_at" type="datetime-local" class="rounded-xl border-slate-200" /></label>
                    <label class="grid gap-1.5"><span class="text-sm font-bold text-slate-700">Échéance</span><input v-model="taskForm.due_at" type="datetime-local" class="rounded-xl border-slate-200" /><InputError :message="taskForm.errors.due_at" /></label>
                    <label class="grid gap-1.5 sm:col-span-2"><span class="text-sm font-bold text-slate-700">Description</span><textarea v-model="taskForm.description" rows="3" class="rounded-xl border-slate-200" /></label>
                    <fieldset class="sm:col-span-2"><legend class="text-sm font-bold text-slate-700">Assignés</legend><div class="mt-2 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2"><label v-for="member in work_session.members" :key="member.id" class="flex items-center gap-3 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700"><input v-model="taskForm.assignee_ids" type="checkbox" :value="member.id" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />{{ member.name }}</label></div><InputError :message="taskForm.errors.assignee_ids" /></fieldset>
                </div>
                <div class="mt-7 flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-bold text-slate-600" @click="isTaskFormOpen = false">Annuler</button><button :disabled="taskForm.processing" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-500 disabled:opacity-50">{{ taskForm.processing ? 'Création...' : 'Créer la tâche' }}</button></div>
            </form>
        </Modal>

        <Modal :show="isMemberFormOpen" max-width="md" @close="isMemberFormOpen = false">
            <form class="p-6" @submit.prevent="submitMember"><h3 class="text-xl font-black text-slate-950">Ajouter un membre</h3><p class="mt-1 text-sm text-slate-500">Seuls les utilisateurs du même département sont proposés.</p><select v-model="memberForm.user_id" required class="mt-6 w-full rounded-xl border-slate-200"><option value="">Choisir un utilisateur</option><option v-for="member in availableMembers" :key="member.id" :value="member.id">{{ member.name }} — {{ member.email }}</option></select><InputError class="mt-2" :message="memberForm.errors.user_id" /><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600" @click="isMemberFormOpen = false">Annuler</button><button :disabled="memberForm.processing || !availableMembers.length" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Ajouter</button></div></form>
        </Modal>
    </AuthenticatedLayout>
</template>

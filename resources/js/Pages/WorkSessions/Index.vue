<script setup>
import InputError from '@/Components/InputError.vue';
import AppIcon from '@/Components/AppIcon.vue';
import Modal from '@/Components/Modal.vue';
import { useSweetAlert } from '@/Composables/useSweetAlert';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    organization: Object,
    department: Object,
    work_sessions: Object,
    projects: Array,
    tasks: Array,
    task_statuses: Array,
    members: Array,
    filters: Object,
});

const { confirmAction } = useSweetAlert();
const isPlanningPage = route().current('planning.*');
const indexRouteName = isPlanningPage ? 'planning.index' : 'work-sessions.index';

const isFormOpen = ref(false);
const editingSession = ref(null);
const formStep = ref(1);
const formStepMessage = ref('');
const selectedSessionId = ref(null);
const isPlanningOpen = ref(false);
const editingSlot = ref(null);
const taskTargetSlotId = ref(null);
const expandedSessionIds = ref(new Set(props.work_sessions.data[0] ? [props.work_sessions.data[0].id] : []));
const filterForm = useForm({
    search: props.filters?.search ?? '',
    project_id: props.filters?.project_id ?? '',
    status: props.filters?.status ?? '',
    visibility: props.filters?.visibility ?? '',
});
const sessionForm = useForm({
    project_id: '',
    name: '',
    description: '',
    start_date: '',
    end_date: '',
    visibility: 'department',
    status: 'draft',
    is_active: true,
    member_ids: [],
});
const memberForm = useForm({ work_session_id: '', user_id: '', role: 'member' });
const slotForm = useForm({
    work_session_id: '',
    task_id: '',
    title: '',
    description: '',
    start_at: '',
    end_at: '',
    status: 'planned',
    member_ids: [],
    is_locked: false,
});
const taskForm = useForm({
    time_slot_id: '',
    title: '',
    description: '',
    priority: 'medium',
    status_id: '',
    assignee_ids: [],
    start_at: '',
    due_at: '',
});

const statusLabels = { draft: 'Brouillon', active: 'Active', completed: 'Terminée', archived: 'Archivée' };
const statusClasses = {
    draft: 'bg-slate-100 text-slate-700',
    active: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-emerald-50 text-emerald-700',
    archived: 'bg-amber-50 text-amber-700',
};
const visibilityLabels = { private: 'Privée', department: 'Département' };

const slotStatusLabels = { planned: 'Planifié', in_progress: 'En cours', completed: 'Terminé', cancelled: 'Annulé' };
const memberRoleLabels = { owner: 'Owner', member: 'Membre', viewer: 'Lecteur' };
const selectedSession = computed(() => props.work_sessions.data.find(
    (session) => Number(session.id) === Number(selectedSessionId.value),
) ?? null);
const sessionTasks = computed(() => {
    if (!selectedSession.value?.project_id) return props.tasks;
    return props.tasks.filter((task) => Number(task.project_id) === Number(selectedSession.value.project_id));
});
const availableMembers = computed(() => {
    const participantIds = new Set(selectedSession.value?.members?.map((member) => Number(member.id)) ?? []);
    return props.members.filter((member) => !participantIds.has(Number(member.id)));
});
const sessionWizardSteps = computed(() => editingSession.value
    ? [
        { number: 1, label: 'Informations', color: 'blue' },
        { number: 2, label: 'Période & accès', color: 'violet' },
        { number: 3, label: 'Vérification', color: 'emerald' },
    ]
    : [
        { number: 1, label: 'Informations', color: 'blue' },
        { number: 2, label: 'Période & accès', color: 'violet' },
        { number: 3, label: 'Participants', color: 'cyan' },
        { number: 4, label: 'Vérification', color: 'emerald' },
    ]);
const lastFormStep = computed(() => sessionWizardSteps.value.length);
const selectedProjectName = computed(() => props.projects.find(
    (project) => Number(project.id) === Number(sessionForm.project_id),
)?.name ?? 'Session générale');
const selectedMemberNames = computed(() => props.members
    .filter((member) => sessionForm.member_ids.map(Number).includes(Number(member.id)))
    .map((member) => member.name));

const resetForm = () => {
    sessionForm.reset();
    sessionForm.clearErrors();
    sessionForm.visibility = 'department';
    sessionForm.status = 'draft';
    sessionForm.is_active = true;
    sessionForm.member_ids = [];
};

const openCreate = () => {
    editingSession.value = null;
    resetForm();
    formStep.value = 1;
    formStepMessage.value = '';
    isFormOpen.value = true;
};

const openEdit = (session) => {
    editingSession.value = session;
    sessionForm.clearErrors();
    sessionForm.project_id = session.project_id ?? '';
    sessionForm.name = session.name ?? '';
    sessionForm.description = session.description ?? '';
    sessionForm.start_date = session.start_date?.slice(0, 10) ?? '';
    sessionForm.end_date = session.end_date?.slice(0, 10) ?? '';
    sessionForm.visibility = session.visibility ?? 'department';
    sessionForm.status = session.status ?? 'draft';
    sessionForm.is_active = Boolean(session.is_active);
    sessionForm.member_ids = session.members?.map((member) => member.id) ?? [];
    formStep.value = 1;
    formStepMessage.value = '';
    isFormOpen.value = true;
};

const closeForm = () => {
    if (sessionForm.processing) return;
    isFormOpen.value = false;
    editingSession.value = null;
    formStep.value = 1;
    formStepMessage.value = '';
    resetForm();
};

const validateCurrentFormStep = () => {
    formStepMessage.value = '';

    if (formStep.value === 1 && !sessionForm.name.trim()) {
        formStepMessage.value = 'Saisissez le nom de la session pour continuer.';
        return false;
    }

    if (formStep.value === 2) {
        if (!sessionForm.start_date || !sessionForm.end_date) {
            formStepMessage.value = 'Renseignez les dates de début et de fin.';
            return false;
        }

        if (sessionForm.end_date < sessionForm.start_date) {
            formStepMessage.value = 'La date de fin doit être postérieure à la date de début.';
            return false;
        }
    }

    return true;
};

const nextFormStep = () => {
    if (validateCurrentFormStep() && formStep.value < lastFormStep.value) {
        formStep.value += 1;
    }
};

const previousFormStep = () => {
    formStepMessage.value = '';
    formStep.value = Math.max(1, formStep.value - 1);
};

const submitSession = () => {
    if (formStep.value < lastFormStep.value) {
        nextFormStep();
        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: closeForm,
        onError: (errors) => {
            if (errors.name || errors.project_id || errors.description) {
                formStep.value = 1;
            } else if (errors.start_date || errors.end_date || errors.status || errors.visibility) {
                formStep.value = 2;
            } else if (errors.member_ids) {
                formStep.value = editingSession.value ? lastFormStep.value : 3;
            }
        },
    };
    if (editingSession.value) {
        sessionForm.patch(route('work-sessions.update', editingSession.value.id), options);
        return;
    }
    sessionForm.post(route('work-sessions.store'), options);
};

const applyFilters = () => router.get(route(indexRouteName), filterForm.data(), {
    preserveState: true,
    replace: true,
});

const clearFilters = () => {
    filterForm.reset();
    router.get(route(indexRouteName));
};

const deleteSession = async (session) => {
    if (!await confirmAction({ title: `Supprimer « ${session.name} » ?`, text: 'La session et son planning seront supprimés.', confirmButtonText: 'Supprimer la session' })) return;
    router.delete(route('work-sessions.destroy', session.id), { preserveScroll: true });
};

const openPlanning = (session) => {
    selectedSessionId.value = session.id;
    memberForm.reset();
    memberForm.work_session_id = session.id;
    resetSlotForm();
    isPlanningOpen.value = true;
};

const closePlanning = () => {
    if (memberForm.processing || slotForm.processing) return;
    isPlanningOpen.value = false;
    editingSlot.value = null;
};

const addMember = () => memberForm.post(route('session-members.store'), {
    preserveScroll: true,
    onSuccess: () => {
        memberForm.user_id = '';
        memberForm.role = 'member';
    },
});

const updateMemberRole = (member, role) => router.patch(route('session-members.update', member.pivot.id), { role }, { preserveScroll: true });
const removeMember = async (member) => {
    if (!await confirmAction({ title: `Retirer ${member.name} ?`, text: 'Cette personne ne participera plus à la session.', confirmButtonText: 'Retirer le membre' })) return;
    router.delete(route('session-members.destroy', member.pivot.id), { preserveScroll: true });
};

const toDateTimeInput = (value) => {
    if (!value) return '';
    const date = new Date(value);
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60000).toISOString().slice(0, 16);
};

function resetSlotForm() {
    slotForm.reset();
    slotForm.clearErrors();
    slotForm.work_session_id = selectedSession.value?.id ?? '';
    slotForm.status = 'planned';
    slotForm.member_ids = [];
    slotForm.is_locked = false;
    editingSlot.value = null;
}

const openEditSlot = (slot) => {
    editingSlot.value = slot;
    slotForm.clearErrors();
    slotForm.work_session_id = selectedSession.value.id;
    slotForm.task_id = slot.task_id;
    slotForm.title = slot.title;
    slotForm.description = slot.description ?? '';
    slotForm.start_at = toDateTimeInput(slot.start_at);
    slotForm.end_at = toDateTimeInput(slot.end_at);
    slotForm.status = slot.status;
    slotForm.member_ids = slot.members.map((member) => member.id);
    slotForm.is_locked = Boolean(slot.is_locked);
};

const syncSlotTitle = () => {
    if (!editingSlot.value) slotForm.title = sessionTasks.value.find((task) => Number(task.id) === Number(slotForm.task_id))?.title ?? '';
};

const submitSlot = () => {
    const options = { preserveScroll: true, onSuccess: resetSlotForm };
    if (editingSlot.value) {
        slotForm.patch(route('time-slots.update', editingSlot.value.id), options);
        return;
    }
    slotForm.post(route('time-slots.store'), options);
};

const deleteSlot = async (slot) => {
    if (!await confirmAction({ title: `Supprimer « ${slot.title} » ?`, text: 'Ce créneau sera retiré du planning.', confirmButtonText: 'Supprimer le créneau' })) return;
    router.delete(route('time-slots.destroy', slot.id), { preserveScroll: true });
};

const openTaskCreation = (slot) => {
    taskTargetSlotId.value = slot.id;
    taskForm.reset();
    taskForm.clearErrors();
    taskForm.time_slot_id = slot.id;
    taskForm.status_id = props.task_statuses.find((status) => status.is_default)?.id ?? props.task_statuses[0]?.id ?? '';
    taskForm.assignee_ids = slot.members.map((member) => member.id);
    taskForm.start_at = toDateTimeInput(slot.start_at);
    taskForm.due_at = toDateTimeInput(slot.end_at);
};

const closeTaskCreation = () => {
    taskTargetSlotId.value = null;
    taskForm.reset();
    taskForm.clearErrors();
};

const submitTask = () => taskForm.post(route('tasks.store'), {
    preserveScroll: true,
    onSuccess: closeTaskCreation,
});

const toggleSession = (sessionId) => {
    const nextIds = new Set(expandedSessionIds.value);
    nextIds.has(sessionId) ? nextIds.delete(sessionId) : nextIds.add(sessionId);
    expandedSessionIds.value = nextIds;
};

const isSessionExpanded = (sessionId) => expandedSessionIds.value.has(sessionId);
const sessionProgress = (session) => Math.round(Number(session.tasks_avg_progress ?? 0));
const formatPeriod = (startDate, endDate) => {
    if (!startDate || !endDate) return 'Période non définie';
    const start = new Date(startDate);
    const end = new Date(endDate);
    const formatter = new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' });
    return `Semaine du ${formatter.format(start)} au ${formatter.format(end)}`;
};
const formatSlotDay = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { weekday: 'long' }).format(new Date(value))
    : 'Jour';
const formatTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '--:--';

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '—';
const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '—';
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="`${isPlanningPage ? 'Planning de l’équipe' : 'Sessions de travail'} - WorkPilot`" />

        <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
            <section class="wp-page-header overflow-hidden px-5 py-5 sm:px-7">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-blue-600">{{ organization?.name }} / {{ department?.name }}</p>
                        <h2 class="mt-1 text-2xl font-bold tracking-tight">{{ isPlanningPage ? 'Planning de l’équipe' : 'Sessions de travail' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">Créez les sessions, affectez l’équipe et gérez chaque créneau depuis un seul espace.</p>
                    </div>
                    <button type="button" class="rounded-[4px] bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700" @click="openCreate">＋ Nouvelle session</button>
                </div>
            </section>

            <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(220px,1fr)_220px_180px_180px_auto]" @submit.prevent="applyFilters">
                <input v-model="filterForm.search" type="search" placeholder="Rechercher une session..." class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" />
                <select v-model="filterForm.project_id" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les projets</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option></select>
                <select v-model="filterForm.status" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les statuts</option><option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option></select>
                <select v-model="filterForm.visibility" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Toute visibilité</option><option v-for="(label, value) in visibilityLabels" :key="value" :value="value">{{ label }}</option></select>
                <div class="flex gap-2"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">Filtrer</button><button type="button" class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-bold text-white hover:bg-amber-600" @click="clearFilters">Effacer</button></div>
            </form>

            <section v-if="work_sessions.data.length" class="min-w-0 border border-slate-300 bg-[#f8f9fb]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div><p class="text-[10px] font-bold uppercase tracking-[.15em] text-blue-700">Organisation du travail</p><h3 class="mt-1 text-base font-bold text-slate-900">Sessions disponibles <span class="ml-1 text-sm text-slate-500">({{ work_sessions.total }})</span></h3></div>

                </div>
                <div class="max-w-full overflow-x-auto">
                    <table class="w-full min-w-[1050px] border-collapse text-left text-xs text-slate-700">
                        <thead class="bg-[#e9eef5] text-[10px] font-extrabold uppercase tracking-[.08em] text-slate-600"><tr><th class="px-3 py-3">Session</th><th class="px-3 py-3">Projet</th><th class="px-3 py-3">Période</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-center">Membres</th><th class="px-3 py-3 text-center">Tâches</th><th class="px-3 py-3 text-center">Créneaux</th><th class="min-w-[145px] px-3 py-3">Progression</th><th class="px-3 py-3 text-right">Actions</th></tr></thead>
                        <tbody>
                            <template v-for="session in work_sessions.data" :key="session.id">
                                <tr class="border-t border-slate-200 bg-white hover:bg-[#eef4ff]">
                                    <td class="max-w-[240px] px-3 py-3"><button type="button" class="block max-w-full text-left" @click="toggleSession(session.id)"><span class="block truncate font-bold text-slate-900">{{ session.name }}</span><span class="mt-1 block text-[10px] font-semibold text-blue-700">{{ visibilityLabels[session.visibility] }}</span></button></td>
                                    <td class="max-w-[190px] px-3 py-3"><span class="block truncate" :title="session.project?.name">{{ session.project?.name ?? 'Session générale' }}</span></td>
                                    <td class="whitespace-nowrap px-3 py-3 font-medium">{{ formatDate(session.start_date) }} → {{ formatDate(session.end_date) }}</td>
                                    <td class="px-3 py-3"><span class="inline-flex rounded-[3px] px-2 py-1 text-[11px] font-bold" :class="statusClasses[session.status] ?? statusClasses.draft">{{ statusLabels[session.status] ?? session.status }}</span></td>
                                    <td class="px-3 py-3 text-center font-bold">{{ session.members_count ?? session.members?.length ?? 0 }}</td>
                                    <td class="px-3 py-3 text-center font-bold">{{ session.tasks_count ?? 0 }}</td>
                                    <td class="px-3 py-3 text-center font-bold">{{ session.time_slots_count ?? session.time_slots?.length ?? 0 }}</td>
                                    <td class="px-3 py-3"><div class="flex items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden bg-slate-200"><div class="h-full bg-blue-600" :style="{ width: `${sessionProgress(session)}%` }" /></div><strong class="w-9 text-right text-blue-700">{{ sessionProgress(session) }}%</strong></div></td>
                                    <td class="px-3 py-3"><div class="flex justify-end gap-1"><button type="button" class="wp-session-icon text-indigo-700" title="Afficher les détails" aria-label="Afficher les détails" @click="toggleSession(session.id)"><AppIcon name="chevron-down" class="h-4 w-4 transition-transform" :class="isSessionExpanded(session.id) ? 'rotate-180' : ''" :stroke-width="2.2" /></button><button type="button" class="wp-session-icon border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100" title="Modifier la session" aria-label="Modifier la session" @click="openEdit(session)"><AppIcon name="edit" class="h-4 w-4" :stroke-width="2" /></button><button type="button" class="wp-session-icon border-cyan-200 bg-cyan-50 text-cyan-700 hover:border-cyan-300 hover:bg-cyan-100" title="Gérer le planning" aria-label="Gérer le planning" @click="openPlanning(session)"><AppIcon name="calendar" class="h-4 w-4" :stroke-width="2" /></button><button type="button" class="wp-session-icon border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300 hover:bg-rose-100" title="Supprimer la session" aria-label="Supprimer la session" @click="deleteSession(session)"><AppIcon name="trash" class="h-4 w-4" :stroke-width="2" /></button></div></td>
                                </tr>
                                <tr v-if="isSessionExpanded(session.id)" class="border-t border-blue-100 bg-slate-50/80">
                                    <td colspan="9" class="px-4 py-3">
                                        <div class="grid gap-3 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)_auto] lg:items-start">
                                            <section class="border border-slate-200 bg-white p-3"><div class="flex items-center justify-between gap-3"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Participants</p><button type="button" class="text-xs font-bold text-blue-700" @click="openPlanning(session)">Gérer</button></div><div class="mt-2 flex flex-wrap gap-1.5"><span v-for="member in session.members?.slice(0, 6)" :key="member.id" class="rounded-[3px] bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700">{{ member.name }}</span><span v-if="!session.members?.length" class="text-xs text-slate-400">Aucun participant</span></div></section>
                                            <section class="border border-slate-200 bg-white p-3"><div class="flex items-center justify-between gap-3"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Prochains créneaux</p><button type="button" class="text-xs font-bold text-blue-700" @click="openPlanning(session)">＋ Ajouter</button></div><div class="mt-2 grid gap-1"><button v-for="slot in session.time_slots?.slice(0, 3)" :key="slot.id" type="button" class="flex items-center justify-between gap-3 border-t border-slate-100 py-1.5 text-left first:border-0" @click="openPlanning(session)"><span class="truncate font-semibold text-slate-800">{{ slot.task?.title ?? slot.title }}</span><span class="shrink-0 text-[10px] font-bold text-blue-700">{{ formatTime(slot.start_at) }}–{{ formatTime(slot.end_at) }}</span></button><span v-if="!session.time_slots?.length" class="text-xs text-slate-400">Aucun créneau planifié</span></div></section>
                                            <Link :href="route('work-sessions.show', session.id)" class="inline-flex items-center justify-center rounded-[4px] bg-cyan-600 px-4 py-2 text-xs font-bold text-white hover:bg-cyan-700">Ouvrir la session →</Link>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-else class="rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-600"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><p class="mt-4 text-lg font-black text-slate-900">Aucune session trouvée</p><p class="mt-2 text-sm text-slate-500">Créez votre première session et organisez ses membres, créneaux et tâches.</p><button type="button" class="mt-5 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white shadow-sm transition hover:bg-blue-500" @click="openCreate">+ Nouvelle session</button></section>

            <div v-if="work_sessions.links?.length > 3" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4"><p class="text-sm text-slate-500">{{ work_sessions.from }}–{{ work_sessions.to }} sur {{ work_sessions.total }}</p><div class="flex flex-wrap gap-1"><Link v-for="link in work_sessions.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded-lg border px-3 py-1.5 text-sm" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 text-slate-600', !link.url ? 'pointer-events-none opacity-40' : 'hover:bg-slate-50']" v-html="link.label" /></div></div>
        </div>

        <Modal :show="isFormOpen" max-width="3xl" @close="closeForm">
            <form class="overflow-hidden bg-[#f8f9fb]" @submit.prevent="submitSession">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-7">
                    <div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-blue-700">Configuration guidée</p><h3 class="mt-1 text-xl font-bold text-slate-900">{{ editingSession ? 'Modifier la session' : 'Nouvelle session' }}</h3><p class="mt-1 text-xs text-slate-500">Étape {{ formStep }} sur {{ lastFormStep }} · {{ sessionWizardSteps[formStep - 1]?.label }}</p></div>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-[4px] border border-slate-200 bg-white text-slate-500 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700" aria-label="Fermer" @click="closeForm">✕</button>
                </header>

                <nav class="grid grid-cols-2 border-b border-slate-200 bg-white sm:grid-cols-4" aria-label="Étapes de création">
                    <button v-for="step in sessionWizardSteps" :key="step.number" type="button" class="relative flex items-center gap-2 border-r border-slate-100 px-3 py-3 text-left last:border-r-0" :class="step.number === formStep ? 'bg-blue-50' : step.number < formStep ? 'bg-emerald-50/40' : 'bg-white'" :disabled="step.number > formStep" @click="formStep = step.number; formStepMessage = ''">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold" :class="step.number < formStep ? 'bg-emerald-600 text-white' : step.number === formStep ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-400'">{{ step.number < formStep ? '✓' : step.number }}</span>
                        <span class="min-w-0"><span class="block truncate text-[10px] font-bold uppercase tracking-wide" :class="step.number === formStep ? 'text-blue-700' : step.number < formStep ? 'text-emerald-700' : 'text-slate-400'">Étape {{ step.number }}</span><span class="block truncate text-xs font-semibold text-slate-700">{{ step.label }}</span></span>
                        <span v-if="step.number === formStep" class="absolute inset-x-0 bottom-0 h-0.5 bg-blue-600" />
                    </button>
                </nav>

                <div class="min-h-[330px] p-5 sm:p-7">
                    <section v-if="formStep === 1" class="grid gap-4">
                        <div class="border border-blue-200 bg-blue-50 px-4 py-3"><p class="text-xs font-bold text-blue-800">Commençons par l’essentiel</p><p class="mt-1 text-xs leading-5 text-blue-700">Donnez un nom clair à la session et rattachez-la à un projet si nécessaire.</p></div>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Nom de la session <b class="text-rose-600">*</b></span><input v-model="sessionForm.name" autofocus class="rounded-[4px] border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Ex. Sprint de livraison — Octobre" /><InputError :message="sessionForm.errors.name" /></label>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Projet associé</span><select v-model="sessionForm.project_id" class="rounded-[4px] border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500"><option value="">Session générale — sans projet</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option></select><InputError :message="sessionForm.errors.project_id" /></label>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Objectif / description</span><textarea v-model="sessionForm.description" rows="3" class="resize-none rounded-[4px] border-slate-300 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Décrivez brièvement le résultat attendu…" /><InputError :message="sessionForm.errors.description" /></label>
                    </section>

                    <section v-else-if="formStep === 2" class="grid gap-4 sm:grid-cols-2">
                        <div class="border border-violet-200 bg-violet-50 px-4 py-3 sm:col-span-2"><p class="text-xs font-bold text-violet-800">Planifiez la période et les accès</p><p class="mt-1 text-xs leading-5 text-violet-700">La session restera modifiable après sa création.</p></div>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Date de début <b class="text-rose-600">*</b></span><input v-model="sessionForm.start_date" type="date" class="rounded-[4px] border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500" /><InputError :message="sessionForm.errors.start_date" /></label>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Date de fin <b class="text-rose-600">*</b></span><input v-model="sessionForm.end_date" type="date" :min="sessionForm.start_date" class="rounded-[4px] border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500" /><InputError :message="sessionForm.errors.end_date" /></label>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Statut</span><select v-model="sessionForm.status" class="rounded-[4px] border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500"><option v-for="(label, value) in statusLabels" :key="value" :value="value">{{ label }}</option></select><InputError :message="sessionForm.errors.status" /></label>
                        <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Visibilité</span><select v-model="sessionForm.visibility" class="rounded-[4px] border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500"><option v-for="(label, value) in visibilityLabels" :key="value" :value="value">{{ label }}</option></select><InputError :message="sessionForm.errors.visibility" /></label>
                        <label class="flex items-center gap-3 border border-slate-200 bg-white px-4 py-3 sm:col-span-2"><input v-model="sessionForm.is_active" type="checkbox" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500" /><span><span class="block text-xs font-bold text-slate-800">Session active</span><span class="mt-0.5 block text-[11px] text-slate-500">Elle sera visible et utilisable dès l’enregistrement.</span></span></label>
                    </section>

                    <section v-else-if="!editingSession && formStep === 3" class="grid gap-4">
                        <div class="border border-cyan-200 bg-cyan-50 px-4 py-3"><p class="text-xs font-bold text-cyan-800">Constituez l’équipe</p><p class="mt-1 text-xs leading-5 text-cyan-700">Sélectionnez les participants. Vous serez automatiquement ajouté comme propriétaire.</p></div>
                        <div class="flex items-center justify-between gap-3"><p class="text-xs font-bold text-slate-700">Membres disponibles</p><span class="rounded-[3px] bg-cyan-100 px-2 py-1 text-[10px] font-bold text-cyan-800">{{ sessionForm.member_ids.length }} sélectionné(s)</span></div>
                        <div class="grid max-h-60 gap-2 overflow-y-auto border border-slate-200 bg-white p-3 sm:grid-cols-2"><label v-for="member in members" :key="member.id" class="flex cursor-pointer items-center gap-3 border px-3 py-2.5 transition" :class="sessionForm.member_ids.map(Number).includes(Number(member.id)) ? 'border-cyan-300 bg-cyan-50' : 'border-slate-200 bg-white hover:border-cyan-200'"><input v-model="sessionForm.member_ids" type="checkbox" :value="member.id" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500" /><span class="grid h-8 w-8 place-items-center rounded-full bg-slate-800 text-[10px] font-bold text-white">{{ member.name?.slice(0, 2).toUpperCase() }}</span><span class="min-w-0"><span class="block truncate text-xs font-bold text-slate-800">{{ member.name }}</span><span class="block truncate text-[10px] text-slate-500">{{ member.email }}</span></span></label><p v-if="!members.length" class="py-8 text-center text-xs text-slate-500 sm:col-span-2">Aucun membre disponible dans ce département.</p></div><InputError :message="sessionForm.errors.member_ids" />
                    </section>

                    <section v-else class="grid gap-4">
                        <div class="border border-emerald-200 bg-emerald-50 px-4 py-3"><p class="text-xs font-bold text-emerald-800">Tout est prêt</p><p class="mt-1 text-xs leading-5 text-emerald-700">Vérifiez le résumé avant d’enregistrer la session.</p></div>
                        <dl class="grid border border-slate-200 bg-white sm:grid-cols-2"><div class="border-b border-slate-200 p-4 sm:border-r"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Session</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ sessionForm.name || '—' }}</dd></div><div class="border-b border-slate-200 p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Projet</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedProjectName }}</dd></div><div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Période</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ formatDate(sessionForm.start_date) }} → {{ formatDate(sessionForm.end_date) }}</dd></div><div class="p-4"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Accès</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ statusLabels[sessionForm.status] }} · {{ visibilityLabels[sessionForm.visibility] }}</dd></div></dl>
                        <div v-if="!editingSession" class="border border-slate-200 bg-white p-4"><p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Participants ({{ selectedMemberNames.length }})</p><div class="mt-2 flex flex-wrap gap-1.5"><span v-for="name in selectedMemberNames" :key="name" class="rounded-[3px] bg-cyan-50 px-2 py-1 text-[10px] font-semibold text-cyan-800">{{ name }}</span><span v-if="!selectedMemberNames.length" class="text-xs text-slate-400">Aucun participant supplémentaire</span></div></div>
                    </section>

                    <p v-if="formStepMessage" class="mt-4 border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ formStepMessage }}</p>
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-5 py-4 sm:px-7">
                    <button type="button" class="rounded-[4px] border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="formStep === 1 ? closeForm() : previousFormStep()">{{ formStep === 1 ? 'Annuler' : '← Précédent' }}</button>
                    <div class="flex items-center gap-2"><span class="hidden text-[11px] font-semibold text-slate-400 sm:inline">{{ formStep }}/{{ lastFormStep }}</span><button v-if="formStep < lastFormStep" type="button" class="rounded-[4px] bg-blue-600 px-5 py-2 text-xs font-bold text-white hover:bg-blue-700" @click="nextFormStep">Suivant →</button><button v-else type="submit" :disabled="sessionForm.processing" class="rounded-[4px] bg-emerald-600 px-5 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50">{{ sessionForm.processing ? 'Enregistrement...' : (editingSession ? 'Enregistrer les modifications' : 'Créer la session') }}</button></div>
                </footer>
            </form>
        </Modal>

        <Modal :show="isPlanningOpen" max-width="screen" @close="closePlanning">
            <div v-if="selectedSession" class="flex max-h-[calc(100dvh-2rem)] min-h-[620px] flex-col bg-[#f1f3f6]">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-700">Planning de la session</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">{{ selectedSession.name }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ formatDate(selectedSession.start_date) }} — {{ formatDate(selectedSession.end_date) }} · {{ selectedSession.project?.name ?? 'Session générale' }}</p>
                    </div>
                    <button type="button" class="rounded-[4px] border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50" @click="closePlanning">Fermer</button>
                </header>

                <div class="grid min-h-0 flex-1 lg:grid-cols-[280px_minmax(0,1fr)]">
                    <aside class="overflow-y-auto border-b border-slate-200 bg-white p-4 lg:border-b-0 lg:border-r">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-700">Équipe</p>
                        <h4 class="mt-1 text-base font-bold text-slate-900">Participants</h4>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Seuls les participants de la session peuvent être affectés à un créneau.</p>

                        <form class="mt-4 space-y-3 border border-slate-200 bg-slate-50 p-3" @submit.prevent="addMember">
                            <select v-model="memberForm.user_id" required class="w-full rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Choisir un utilisateur</option>
                                <option v-for="member in availableMembers" :key="member.id" :value="member.id">{{ member.name }} — {{ member.email }}</option>
                            </select>
                            <select v-model="memberForm.role" class="w-full rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option v-for="(label, value) in memberRoleLabels" :key="value" :value="value">{{ label }}</option></select>
                            <InputError :message="memberForm.errors.user_id || memberForm.errors.role" />
                            <button type="submit" :disabled="memberForm.processing || !availableMembers.length" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-40">Ajouter le participant</button>
                        </form>

                        <div class="mt-5 space-y-2">
                            <div v-for="member in selectedSession.members" :key="member.id" class="rounded-xl border border-slate-200 p-3">
                                <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-blue-50 text-xs font-black text-blue-700">{{ member.name?.slice(0, 2).toUpperCase() }}</span><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-800">{{ member.name }}</p><p class="truncate text-xs text-slate-400">{{ member.email }}</p></div></div>
                                <div class="mt-3 flex items-center gap-2">
                                    <select :value="member.pivot.role" :disabled="member.pivot.role === 'owner'" class="min-w-0 flex-1 rounded-lg border-slate-200 py-1.5 text-xs" @change="updateMemberRole(member, $event.target.value)"><option v-for="(label, value) in memberRoleLabels" :key="value" :value="value">{{ label }}</option></select>
                                    <button v-if="member.pivot.role !== 'owner'" type="button" class="text-xs font-bold text-rose-600 hover:text-rose-800" @click="removeMember(member)">Retirer</button>
                                </div>
                            </div>
                            <p v-if="!selectedSession.members?.length" class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-400">Aucun participant.</p>
                        </div>
                    </aside>

                    <main class="overflow-y-auto p-4 sm:p-5">
                        <div class="grid gap-4 xl:grid-cols-[340px_minmax(0,1fr)]">
                            <form class="h-fit border border-slate-200 bg-white p-4" @submit.prevent="submitSlot">
                                <div class="flex items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-700">Planification</p><h4 class="mt-1 text-base font-bold text-slate-900">{{ editingSlot ? 'Modifier le créneau' : 'Nouveau créneau' }}</h4></div><button v-if="editingSlot" type="button" class="text-xs font-bold text-slate-500" @click="resetSlotForm">Annuler</button></div>
                                <p class="mt-2 text-xs leading-5 text-slate-500">La tâche indique quoi faire. Le créneau indique quand et par qui elle sera exécutée.</p>
                                <div class="mt-4 space-y-3">
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Titre du créneau</span><input v-model="slotForm.title" required placeholder="Ex. Lundi matin — développement" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError :message="slotForm.errors.title" /></label>
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Tâche existante <span class="font-normal text-slate-400">(optionnelle)</span></span><select v-model="slotForm.task_id" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" @change="syncSlotTitle"><option value="">Créer la tâche après le créneau</option><option v-for="task in sessionTasks" :key="task.id" :value="task.id">{{ task.title }}</option></select><InputError :message="slotForm.errors.task_id" /></label>
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Début réel</span><input v-model="slotForm.start_at" type="datetime-local" required class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError :message="slotForm.errors.start_at" /></label>
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Fin réelle</span><input v-model="slotForm.end_at" type="datetime-local" required class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /><InputError :message="slotForm.errors.end_at" /></label>
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Statut</span><select v-model="slotForm.status" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500"><option v-for="(label, value) in slotStatusLabels" :key="value" :value="value">{{ label }}</option></select><InputError :message="slotForm.errors.status" /></label>
                                    <label class="grid gap-1.5"><span class="text-xs font-bold text-slate-700">Note du créneau</span><textarea v-model="slotForm.description" rows="2" class="rounded-xl border-slate-200 text-sm focus:border-blue-500 focus:ring-blue-500" /></label>
                                    <fieldset><legend class="text-xs font-bold text-slate-700">Membres affectés</legend><div class="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3"><label v-for="member in selectedSession.members" :key="member.id" class="flex items-center gap-2 text-sm text-slate-700"><input v-model="slotForm.member_ids" type="checkbox" :value="member.id" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />{{ member.name }}</label><p v-if="!selectedSession.members?.length" class="text-xs text-slate-400">Ajoutez d’abord des participants.</p></div><InputError :message="slotForm.errors.member_ids" /></fieldset>
                                    <button type="submit" :disabled="slotForm.processing || !selectedSession.members?.length" class="w-full rounded-[4px] bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-40">{{ slotForm.processing ? 'Enregistrement...' : (editingSlot ? 'Enregistrer les modifications' : 'Planifier la tâche') }}</button>
                                </div>
                            </form>

                            <section>
                                <div class="flex items-end justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-blue-600">Exécution réelle</p><h4 class="mt-1 text-xl font-black text-slate-900">Créneaux planifiés</h4></div><span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600 shadow-sm">{{ selectedSession.time_slots?.length ?? 0 }} créneau(x)</span></div>
                                <div class="mt-4 space-y-3">
                                    <article v-for="slot in selectedSession.time_slots" :key="slot.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                        <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><p class="text-sm font-black text-slate-900">{{ slot.task?.title ?? slot.title }}</p><p class="mt-1 capitalize text-xs font-semibold text-blue-600">{{ formatDateTime(slot.start_at) }} → {{ formatDateTime(slot.end_at) }}</p></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-700">{{ slotStatusLabels[slot.status] ?? slot.status }}</span></div>
                                        <p v-if="slot.description" class="mt-3 text-sm text-slate-500">{{ slot.description }}</p>
                                        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3"><div class="flex flex-wrap gap-1.5"><span v-for="member in slot.members" :key="member.id" class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-bold text-blue-700">{{ member.name }}</span></div><div v-if="!slot.is_locked" class="flex gap-3"><button v-if="!slot.task" type="button" class="text-xs font-bold text-emerald-600" @click="openTaskCreation(slot)">+ Créer la tâche</button><button type="button" class="text-xs font-bold text-blue-600" @click="openEditSlot(slot)">Modifier</button><button type="button" class="text-xs font-bold text-rose-600" @click="deleteSlot(slot)">Supprimer</button></div></div>
                                        <form v-if="taskTargetSlotId === slot.id" class="mt-4 grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 sm:grid-cols-2" @submit.prevent="submitTask">
                                            <div class="sm:col-span-2"><p class="text-xs font-black uppercase tracking-wider text-emerald-700">Nouvelle tâche dans ce créneau</p><p class="mt-1 text-xs text-slate-500">Les dates et les responsables sont repris automatiquement du créneau.</p></div>
                                            <label class="grid gap-1"><span class="text-xs font-bold text-slate-700">Titre</span><input v-model="taskForm.title" required class="rounded-lg border-slate-200 text-sm focus:border-emerald-500 focus:ring-emerald-500" /><InputError :message="taskForm.errors.title" /></label>
                                            <label class="grid gap-1"><span class="text-xs font-bold text-slate-700">Priorité</span><select v-model="taskForm.priority" class="rounded-lg border-slate-200 text-sm"><option value="low">Basse</option><option value="medium">Moyenne</option><option value="high">Haute</option><option value="critical">Critique</option></select></label>
                                            <label class="grid gap-1"><span class="text-xs font-bold text-slate-700">Statut</span><select v-model="taskForm.status_id" class="rounded-lg border-slate-200 text-sm"><option v-for="status in task_statuses" :key="status.id" :value="status.id">{{ status.name }}</option></select><InputError :message="taskForm.errors.status_id" /></label>
                                            <label class="grid gap-1 sm:col-span-2"><span class="text-xs font-bold text-slate-700">Description</span><textarea v-model="taskForm.description" rows="2" class="rounded-lg border-slate-200 text-sm" /></label>
                                            <InputError class="sm:col-span-2" :message="taskForm.errors.time_slot_id || taskForm.errors.assignee_ids" />
                                            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600" @click="closeTaskCreation">Annuler</button><button type="submit" :disabled="taskForm.processing" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-500 disabled:opacity-50">{{ taskForm.processing ? 'Création...' : 'Créer et associer' }}</button></div>
                                        </form>
                                    </article>
                                    <div v-if="!selectedSession.time_slots?.length" class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center"><p class="font-black text-slate-800">Aucun créneau planifié</p><p class="mt-2 text-sm text-slate-500">Sélectionnez une tâche, une période réelle et les collaborateurs affectés.</p></div>
                                </div>
                            </section>
                        </div>
                    </main>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.wp-session-icon {
    display: inline-flex;
    width: 30px;
    height: 30px;
    align-items: center;
    justify-content: center;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    background: #fff;
    font-size: 15px;
    font-weight: 700;
}

.wp-session-icon:hover {
    border-color: #93c5fd;
    background: #eff6ff;
}

.session-expand-enter-active,
.session-expand-leave-active {
    transition:
        opacity 240ms ease,
        transform 240ms ease;
}

.session-expand-enter-from,
.session-expand-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>

<script setup>
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useSweetAlert } from '@/Composables/useSweetAlert';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    organization: Object,
    department: Object,
    action_plans: Object,
    filters: Object,
    statuses: Array,
    openActionPlanId: Number,
});

const { confirmAction } = useSweetAlert();

const planModalOpen = ref(false);
const axisModalOpen = ref(false);
const editingPlan = ref(null);
const editingAxis = ref(null);
const editingActionId = ref(null);
const commentingActionId = ref(null);
const editingCommentId = ref(null);
const deletingCommentId = ref(null);
const duplicatingActionId = ref(null);
const consultationPlan = ref(null);
const consultationProjectId = ref(null);
const consultationSearch = ref('');
const projectPickerOpen = ref(false);
const showValue = (value) => value === null || value === undefined || String(value).trim() === '' ? '--' : value;
const expandedConsultationActionId = ref(null);

const filterForm = useForm({
    search: props.filters?.search ?? '',
    status: props.filters?.status ?? '',
});

const planForm = useForm({
    name: '',
    code: '',
    description: '',
    start_date: '',
    end_date: '',
    status: 'draft',
    progress: 0,
    is_active: true,
});

const axisForm = useForm({
    action_plan_id: '',
    name: '',
    code: '',
    description: '',
    position: 0,
    is_active: true,
});

const actionProgressForm = useForm({
    progress: 0,
    start_date: '',
    due_date: '',
    actual_start_date: '',
    actual_end_date: '',
});

const actionCommentForm = useForm({
    action_id: '',
    content: '',
});

const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id ?? null);

const statusLabels = {
    draft: 'Brouillon',
    active: 'Actif',
    completed: 'Terminé',
    archived: 'Archivé',
};

const statusClasses = {
    draft: 'bg-slate-100 text-slate-700',
    active: 'bg-emerald-50 text-emerald-700',
    completed: 'bg-blue-50 text-blue-700',
    archived: 'bg-amber-50 text-amber-700',
};

const projectStatusLabels = {
    draft: 'Brouillon',
    planned: 'Planifié',
    in_progress: 'En cours',
    on_hold: 'En attente',
    completed: 'Terminé',
    cancelled: 'Annulé',
};

const actionStatusLabels = {
    not_started: 'Non débutée',
    in_progress: 'En cours',
    on_hold: 'En attente',
    completed: 'Terminée',
    cancelled: 'Annulée',
};

const actionStatusClasses = {
    not_started: 'bg-slate-100 text-slate-700',
    in_progress: 'bg-blue-50 text-blue-700',
    on_hold: 'bg-amber-50 text-amber-700',
    completed: 'bg-emerald-50 text-emerald-700',
    cancelled: 'bg-rose-50 text-rose-700',
};

const progressColor = (progress) => {
    if (progress >= 100) return 'bg-emerald-500';
    if (progress >= 50) return 'bg-blue-600';
    if (progress > 0) return 'bg-amber-500';

    return 'bg-slate-300';
};

const selectedConsultationProject = computed(() =>
    consultationPlan.value?.projects.find(
        (project) => Number(project.id) === Number(consultationProjectId.value),
    ) ?? null,
);

const sortedConsultationProjects = computed(() => {
    const search = consultationSearch.value.trim().toLocaleLowerCase('fr');
    const projects = (consultationPlan.value?.projects ?? []).filter((project) => (
        !search
        || project.name.toLocaleLowerCase('fr').includes(search)
        || project.axis?.name?.toLocaleLowerCase('fr').includes(search)
    ));

    return [...projects].sort((firstProject, secondProject) => {
        const positionDifference = (Number(firstProject.position) || Number.MAX_SAFE_INTEGER)
            - (Number(secondProject.position) || Number.MAX_SAFE_INTEGER);

        if (positionDifference !== 0) return positionDifference;

        const firstProgress = Number(firstProject.progress) || 0;
        const secondProgress = Number(secondProject.progress) || 0;
        const progressGroup = (progress) => {
            if (progress > 0 && progress < 100) return 0;
            if (progress === 0) return 1;

            return 2;
        };
        const groupDifference = progressGroup(firstProgress) - progressGroup(secondProgress);

        if (groupDifference !== 0) return groupDifference;
        if (firstProgress > 0 && firstProgress < 100) return secondProgress - firstProgress;

        return 0;
    });
});

const openConsultation = (plan) => {
    consultationPlan.value = plan;
    consultationProjectId.value = sortedConsultationProjects.value[0]?.id ?? null;
    consultationSearch.value = '';
    projectPickerOpen.value = false;
};

onMounted(() => {
    if (!props.openActionPlanId) return;

    const plan = props.action_plans.data.find(
        (item) => Number(item.id) === Number(props.openActionPlanId),
    );

    if (plan) openConsultation(plan);
});

const refreshConsultationPlan = (page) => {
    const refreshedPlan = page.props.action_plans.data.find(
        (plan) => Number(plan.id) === Number(consultationPlan.value.id),
    );

    if (refreshedPlan) {
        consultationPlan.value = refreshedPlan;
    }
};

const closeConsultation = () => {
    if (actionProgressForm.processing || actionCommentForm.processing) return;

    consultationPlan.value = null;
    consultationSearch.value = '';
    consultationProjectId.value = null;
    expandedConsultationActionId.value = null;
    editingActionId.value = null;
    commentingActionId.value = null;
    editingCommentId.value = null;
    actionProgressForm.reset();
    actionProgressForm.clearErrors();
    actionCommentForm.reset();
    actionCommentForm.clearErrors();
};

const openActionProgress = (action) => {
    editingActionId.value = action.id;
    actionProgressForm.clearErrors();
    actionProgressForm.progress = action.progress;
    actionProgressForm.start_date = dateValue(action.start_date);
    actionProgressForm.due_date = dateValue(action.due_date);
    actionProgressForm.actual_start_date = dateValue(action.actual_start_date);
    actionProgressForm.actual_end_date = dateValue(action.actual_end_date);
};

const cancelActionProgress = () => {
    if (actionProgressForm.processing) return;

    editingActionId.value = null;
    actionProgressForm.reset();
    actionProgressForm.clearErrors();
};

const submitActionProgress = (action) => {
    actionProgressForm.patch(route('actions.update', action.id), {
        preserveScroll: true,
        onSuccess: (page) => {
            refreshConsultationPlan(page);
            editingActionId.value = null;
            actionProgressForm.reset();
        },
    });
};

const openActionComment = (action) => {
    commentingActionId.value = action.id;
    editingCommentId.value = null;
    actionCommentForm.clearErrors();
    actionCommentForm.action_id = action.id;
    actionCommentForm.content = '';
};

const openCommentEdit = (action, comment) => {
    commentingActionId.value = action.id;
    editingCommentId.value = comment.id;
    actionCommentForm.clearErrors();
    actionCommentForm.action_id = action.id;
    actionCommentForm.content = comment.content;
};

const cancelActionComment = () => {
    if (actionCommentForm.processing) return;

    commentingActionId.value = null;
    editingCommentId.value = null;
    actionCommentForm.reset();
    actionCommentForm.clearErrors();
};

const submitActionComment = () => {
    const options = {
        preserveScroll: true,
        onSuccess: (page) => {
            refreshConsultationPlan(page);
            commentingActionId.value = null;
            editingCommentId.value = null;
            actionCommentForm.reset();
        },
    };

    if (editingCommentId.value) {
        actionCommentForm.transform(({ content }) => ({ content })).patch(
            route('action-comments.update', editingCommentId.value),
            options,
        );

        return;
    }

    actionCommentForm.transform((data) => data).post(route('action-comments.store'), options);
};

const removeActionComment = async (comment) => {
    if (!await confirmAction({ title: 'Supprimer ce commentaire ?', text: 'Le commentaire disparaîtra définitivement.', confirmButtonText: 'Supprimer' })) return;

    router.delete(route('action-comments.destroy', comment.id), {
        preserveScroll: true,
        onStart: () => {
            deletingCommentId.value = comment.id;
        },
        onSuccess: refreshConsultationPlan,
        onFinish: () => {
            deletingCommentId.value = null;
        },
    });
};

const duplicateAction = (action) => {
    router.post(route('actions.duplicate', action.id), {}, {
        preserveScroll: true,
        onStart: () => {
            duplicatingActionId.value = action.id;
        },
        onSuccess: refreshConsultationPlan,
        onFinish: () => {
            duplicatingActionId.value = null;
        },
    });
};

const dateValue = (value) => value?.slice(0, 10) ?? '';

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value))
    : '—';

const formatDateTime = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '';

const userInitial = (name) => name?.trim().charAt(0).toUpperCase() || '?';

const openCreatePlan = () => {
    editingPlan.value = null;
    planForm.reset();
    planForm.clearErrors();
    planForm.status = 'draft';
    planForm.progress = 0;
    planForm.is_active = true;
    planModalOpen.value = true;
};

const openEditPlan = (plan) => {
    editingPlan.value = plan;
    planForm.clearErrors();
    planForm.name = plan.name;
    planForm.code = plan.code ?? '';
    planForm.description = plan.description ?? '';
    planForm.start_date = dateValue(plan.start_date);
    planForm.end_date = dateValue(plan.end_date);
    planForm.status = plan.status;
    planForm.progress = plan.progress;
    planForm.is_active = Boolean(plan.is_active);
    planModalOpen.value = true;
};

const closePlanModal = () => {
    if (planForm.processing) return;
    planModalOpen.value = false;
    editingPlan.value = null;
};

const submitPlan = () => {
    const options = { preserveScroll: true, onSuccess: closePlanModal };

    if (editingPlan.value) {
        planForm.patch(route('action-plans.update', editingPlan.value.id), options);
    } else {
        planForm.post(route('action-plans.store'), options);
    }
};

const openCreateAxis = (plan) => {
    editingAxis.value = null;
    axisForm.reset();
    axisForm.clearErrors();
    axisForm.action_plan_id = plan.id;
    axisForm.position = plan.axes.length;
    axisForm.is_active = true;
    axisModalOpen.value = true;
};

const openEditAxis = (axis) => {
    editingAxis.value = axis;
    axisForm.clearErrors();
    axisForm.action_plan_id = axis.action_plan_id;
    axisForm.name = axis.name;
    axisForm.code = axis.code ?? '';
    axisForm.description = axis.description ?? '';
    axisForm.position = axis.position;
    axisForm.is_active = Boolean(axis.is_active);
    axisModalOpen.value = true;
};

const closeAxisModal = () => {
    if (axisForm.processing) return;
    axisModalOpen.value = false;
    editingAxis.value = null;
};

const submitAxis = () => {
    const options = { preserveScroll: true, onSuccess: closeAxisModal };

    if (editingAxis.value) {
        axisForm.patch(route('axes.update', editingAxis.value.id), options);
    } else {
        axisForm.post(route('axes.store'), options);
    }
};

const applyFilters = () => router.get(route('action-plans.index'), filterForm.data(), { preserveState: true, replace: true });
const resetFilters = () => {
    filterForm.reset();
    router.get(route('action-plans.index'), {}, { replace: true });
};

const removePlan = async (plan) => {
    if (await confirmAction({ title: `Supprimer « ${plan.name} » ?`, text: 'Le plan et les données associées seront supprimés.', confirmButtonText: 'Supprimer le plan' })) {
        router.delete(route('action-plans.destroy', plan.id), { preserveScroll: true });
    }
};

const removeAxis = async (axis) => {
    if (await confirmAction({ title: `Supprimer « ${axis.name} » ?`, text: 'Cet axe ne sera plus disponible dans le plan.', confirmButtonText: 'Supprimer l’axe' })) {
        router.delete(route('axes.destroy', axis.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Plans d’action - WorkPilot" />

    <AuthenticatedLayout>
        <div class="min-w-0 max-w-full overflow-x-hidden bg-[#f1f3f6]">
            <div class="mx-auto grid min-w-0 max-w-[1700px] gap-4 px-3 py-5 sm:px-5 lg:px-7">
                <header class="border border-slate-300 bg-[#f8f9fb] px-5 py-5 sm:px-7">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div><p class="text-[11px] font-bold uppercase tracking-[.15em] text-blue-700">{{ organization.name }} · {{ department.name }}</p><h1 class="mt-1 text-2xl font-bold text-slate-950">Plans d’action</h1><p class="mt-1 text-sm text-slate-600">Pilotage stratégique des plans, axes et projets</p></div>
                        <button type="button" class="wp-btn wp-btn-primary" @click="openCreatePlan"><span class="text-lg">＋</span> Nouveau plan</button>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="wp-kpi border-blue-200 bg-blue-50"><span>Plans d’action</span><strong>{{ action_plans.total ?? action_plans.data.length }}</strong></div>
                        <div class="wp-kpi border-violet-200 bg-violet-50"><span>Axes stratégiques</span><strong>{{ action_plans.data.reduce((n,p) => n + Number(p.axes_count || 0), 0) }}</strong></div>
                        <div class="wp-kpi border-cyan-200 bg-cyan-50"><span>Projets</span><strong>{{ action_plans.data.reduce((n,p) => n + Number(p.projects_count || 0), 0) }}</strong></div>
                        <div class="wp-kpi border-emerald-200 bg-emerald-50"><span>Plans actifs</span><strong>{{ action_plans.data.filter(p => p.status === 'active').length }}</strong></div>
                    </div>
                </header>
                <section class="border border-slate-300 bg-[#f8f9fb]">
                    <form class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4" @submit.prevent="applyFilters">
                        <label class="min-w-[210px] flex-1 text-xs font-semibold text-slate-600">Rechercher<input v-model="filterForm.search" type="search" placeholder="Nom ou code du plan..." class="wp-field mt-1" /></label>
                        <label class="w-full text-xs font-semibold text-slate-600 sm:w-52">Statut<select v-model="filterForm.status" class="wp-field mt-1"><option value="">Tous les statuts</option><option v-for="status in statuses" :key="status" :value="status">{{ statusLabels[status] }}</option></select></label>
                        <button class="wp-btn wp-btn-primary" type="submit">Filtrer</button><button type="button" class="wp-btn wp-btn-secondary" @click="resetFilters">Réinitialiser</button>
                    </form>
                    <div class="overflow-x-auto">
                        <table class="wp-table min-w-[1050px]">
                            <thead><tr><th>Plan d’action</th><th>Code</th><th>Statut</th><th>Début</th><th>Échéance</th><th>Axes</th><th>Projets</th><th>Actions</th><th>Progression</th><th class="text-right">Actions</th></tr></thead>
                            <tbody>
                                <template v-for="plan in action_plans.data" :key="plan.id">
                                    <tr>
                                        <td class="max-w-[250px]"><div class="font-semibold text-slate-900">{{ plan.name }}</div><div v-if="plan.description" class="mt-1 truncate text-xs text-slate-500">{{ plan.description }}</div></td>
                                        <td class="font-mono text-xs font-semibold text-blue-700">{{ plan.code || '—' }}</td>
                                        <td><span class="wp-status" :class="statusClasses[plan.status]">{{ statusLabels[plan.status] || plan.status }}</span></td>
                                        <td class="whitespace-nowrap text-sky-800">{{ formatDate(plan.start_date) }}</td><td class="whitespace-nowrap font-medium text-amber-800">{{ formatDate(plan.end_date) }}</td>
                                        <td class="text-center font-semibold">{{ plan.axes_count }}</td><td class="text-center font-semibold">{{ plan.projects_count }}</td><td class="text-center font-semibold">{{ plan.actions_count }}</td>
                                        <td><div class="flex min-w-[110px] items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden bg-slate-200"><div class="h-full" :class="progressColor(plan.progress)" :style="{ width: `${Math.min(100, Math.max(0, Number(plan.progress)||0))}%` }"></div></div><span class="text-xs font-bold text-blue-700">{{ plan.progress }}%</span></div></td>
                                        <td><div class="flex justify-end gap-1"><button type="button" class="wp-icon-btn text-blue-700" title="Consulter" aria-label="Consulter" @click="openConsultation(plan)"><Eye class="h-4 w-4" aria-hidden="true" /></button><button type="button" class="wp-icon-btn text-violet-700" title="Modifier" aria-label="Modifier" @click="openEditPlan(plan)">✎</button><button type="button" class="wp-icon-btn text-emerald-700" title="Ajouter un axe" aria-label="Ajouter un axe" @click="openCreateAxis(plan)">＋</button><button type="button" class="wp-icon-btn text-rose-700" title="Supprimer" aria-label="Supprimer" @click="removePlan(plan)">✕</button></div></td>
                                    </tr>
                                    <tr v-if="plan.axes?.length" class="bg-slate-50/80"><td colspan="10" class="!px-5 !py-2"><details class="group"><summary class="cursor-pointer text-xs font-bold text-slate-600 hover:text-blue-700">▸ Axes stratégiques ({{ plan.axes.length }})</summary><div class="mt-3 overflow-x-auto border border-slate-200 bg-white"><table class="wp-table min-w-[700px]"><thead><tr><th>Axe</th><th>Code</th><th>Projets</th><th>Actions</th><th class="text-right">Actions</th></tr></thead><tbody><tr v-for="axis in plan.axes" :key="axis.id"><td class="font-semibold">{{ axis.name }}</td><td class="font-mono text-xs">{{ axis.code || '—' }}</td><td>{{ axis.projects_count }}</td><td>{{ axis.actions_count }}</td><td><div class="flex justify-end gap-2"><button type="button" class="wp-icon-btn text-blue-700" title="Modifier l’axe" @click="openEditAxis(axis)">✎</button><button type="button" class="wp-icon-btn text-rose-700" title="Supprimer l’axe" @click="removeAxis(axis)">✕</button></div></td></tr></tbody></table></div></details></td></tr>
                                </template>
                                <tr v-if="!action_plans.data.length"><td colspan="10" class="py-12 text-center text-slate-500">Aucun plan d’action trouvé.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <div v-if="action_plans.links?.length > 3" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-5 py-4">
                    <p class="text-sm text-slate-500">{{ action_plans.from }}–{{ action_plans.to }} sur {{ action_plans.total }}</p>
                    <div class="flex gap-1"><Link v-for="link in action_plans.links" :key="link.label" :href="link.url || '#'" preserve-scroll class="rounded border px-3 py-1.5 text-sm" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 text-slate-600', !link.url ? 'pointer-events-none opacity-40' : 'hover:bg-slate-50']" v-html="link.label" /></div>
                </div>
            </div>
        </div>

        <Modal :show="Boolean(consultationPlan)" max-width="full" @close="closeConsultation">
            <div v-if="consultationPlan" class="wp-modal wp-consultation-content flex h-full flex-col overflow-hidden bg-[#f1f3f6]">
                <header class="shrink-0 border-b border-slate-200 bg-white px-4 py-4 sm:px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 text-[11px] font-semibold text-slate-500">
                                <span>Plans d’action</span><span class="text-slate-300">/</span><span class="truncate text-blue-600">{{ consultationPlan.name }}</span>
                            </div>
                            <h2 class="mt-1 truncate text-xl font-bold tracking-tight text-slate-950 sm:text-2xl">{{ consultationPlan.name }}</h2>
                            <p class="mt-1 truncate text-xs text-slate-500">{{ consultationPlan.description || 'Vue consolidée des projets et de leurs actions.' }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Link :href="route('projects.index', { action_plan_id: consultationPlan.id, create_project: 1 })" class="rounded bg-blue-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-blue-500">Nouveau projet</Link>
                            <button type="button" class="grid h-9 w-9 place-items-center rounded border border-slate-200 bg-white text-slate-500 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700" aria-label="Fermer" @click="closeConsultation">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg>
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-5">
                        <div class="rounded-[3px] border border-blue-200 bg-blue-50 px-3 py-2"><p class="text-lg font-bold text-blue-800">{{ consultationPlan.progress }}%</p><p class="text-[10px] font-bold uppercase tracking-wide text-blue-700">Progression</p></div>
                        <div class="rounded-[3px] border border-violet-200 bg-violet-50 px-3 py-2"><p class="text-lg font-bold text-violet-800">{{ consultationPlan.projects_count }}</p><p class="text-[10px] font-bold uppercase tracking-wide text-violet-700">Projets</p></div>
                        <div class="rounded-[3px] border border-cyan-200 bg-cyan-50 px-3 py-2"><p class="text-lg font-bold text-cyan-800">{{ consultationPlan.actions_count }}</p><p class="text-[10px] font-bold uppercase tracking-wide text-cyan-700">Actions</p></div>
                        <div class="rounded-[3px] border border-amber-200 bg-amber-50 px-3 py-2"><p class="text-sm font-bold text-amber-900">{{ formatDate(consultationPlan.end_date) }}</p><p class="text-[10px] font-bold uppercase tracking-wide text-amber-800">Échéance</p></div>
                        <div class="col-span-2 rounded-[3px] border border-emerald-200 bg-emerald-50 px-3 py-2 lg:col-span-1">
                            <p class="truncate text-sm font-bold text-emerald-900" :title="selectedConsultationProject?.name || ''">{{ selectedConsultationProject?.name || '--' }}</p>
                            <p class="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800">Projet sélectionné · {{ selectedConsultationProject?.progress ?? '--' }}%</p>
                        </div>
                    </div>
                </header>

                <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <!-- Sélecteur de projet avec recherche, au-dessus du tableau -->
                    <div class="relative z-20 border-b border-slate-300 bg-[#f8f9fb] px-4 py-3 sm:px-5">
                        <label class="mb-1.5 block text-[11px] font-bold uppercase tracking-wide text-slate-600" for="consultation-project-search">Sélectionner un projet</label>
                        <div class="relative w-full">
                            <div class="flex items-center gap-2 border border-slate-300 bg-white px-3 focus-within:border-blue-500 focus-within:ring-1 focus-within:ring-blue-200">
                                <svg class="h-4 w-4 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.8-3.8"/></svg>
                                <input id="consultation-project-search" v-model="consultationSearch" type="search" autocomplete="off" :placeholder="selectedConsultationProject?.name || 'Rechercher un projet par nom ou axe...'" class="min-w-0 flex-1 border-0 bg-transparent py-2.5 text-sm text-slate-900 placeholder:text-slate-500 focus:ring-0" @focus="projectPickerOpen = true" @input="projectPickerOpen = true" @keydown.esc="projectPickerOpen = false" />
                                <span class="shrink-0 border-l border-slate-200 pl-2 text-xs font-semibold text-blue-700">{{ consultationPlan.projects.length }} projets</span>
                                <button type="button" class="p-1 text-slate-500 hover:text-blue-700" aria-label="Afficher les projets" :aria-expanded="projectPickerOpen" @click="projectPickerOpen = !projectPickerOpen"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></button>
                            </div>
                            <div v-if="projectPickerOpen" class="absolute left-0 right-0 top-full z-30 mt-1 max-h-64 overflow-y-auto border border-slate-300 bg-white shadow-lg">
                                <button v-for="project in sortedConsultationProjects" :key="project.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-3 py-2.5 text-left text-sm hover:bg-blue-50" :class="Number(consultationProjectId) === Number(project.id) ? 'bg-blue-50 text-blue-800' : 'text-slate-800'" @click="consultationProjectId = project.id; consultationSearch = ''; projectPickerOpen = false; expandedConsultationActionId = null">
                                    <span class="min-w-0"><span class="block truncate font-semibold">#{{ project.position || '—' }} · {{ project.name }}</span><span class="block truncate text-[11px] text-slate-500">{{ project.axis?.name || '--' }} · {{ project.departments?.map((department) => department.name).join(', ') || 'Aucun département' }}</span></span>
                                    <span class="shrink-0 text-xs font-bold" :class="Number(project.progress) >= 100 ? 'text-emerald-700' : 'text-blue-700'">{{ project.progress }}%</span>
                                </button>
                                <div v-if="!sortedConsultationProjects.length" class="px-3 py-4 text-sm text-slate-500">Aucun projet correspondant.</div>
                            </div>
                        </div>

                    </div>

                    <main class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-5">
                        <div v-if="selectedConsultationProject" class="grid gap-4">


                            <div class="flex items-center justify-between gap-3"><div><p class="text-[10px] font-black uppercase tracking-[0.17em] text-blue-600">Exécution</p><h3 class="mt-1 text-lg font-black text-slate-900">Actions du projet</h3></div><span class="rounded-full bg-white px-3 py-1.5 text-xs font-bold text-slate-500 shadow-sm">{{ selectedConsultationProject.actions.length }} action(s)</span></div>

                            <div v-if="selectedConsultationProject.actions.length" class="overflow-hidden rounded border border-slate-300 bg-[#f8f9fb]">
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[1200px] border-collapse text-left text-xs">
                                        <thead class="bg-[#e9eef5] text-[10px] font-bold uppercase tracking-wider text-slate-600">
                                            <tr>
                                                <th class="px-3 py-3">#</th><th class="min-w-[240px] px-3 py-3">Action</th><th class="px-3 py-3">Statut</th>
                                                <th class="px-3 py-3 text-sky-800">Début prévu</th><th class="px-3 py-3 text-amber-800">Fin prévue</th>
                                                <th class="px-3 py-3 text-violet-800">Début réel</th><th class="px-3 py-3 text-emerald-800">Fin réelle</th>
                                                <th class="min-w-[125px] px-3 py-3">Progression</th><th class="px-3 py-3 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template v-for="(action, index) in selectedConsultationProject.actions" :key="action.id">
                                                <tr class="border-t border-slate-200 transition hover:bg-blue-50/40" :class="expandedConsultationActionId === action.id ? 'bg-blue-50/60' : index % 2 ? 'bg-white' : 'bg-[#f8f9fb]'">
                                                    <td class="px-3 py-3 font-semibold text-slate-500">{{ index + 1 }}</td>
                                                    <td class="px-3 py-3"><div class="font-semibold text-slate-900">{{ action.title }}</div><div v-if="action.description" class="mt-1 max-w-[280px] truncate text-[11px] text-slate-500">{{ action.description }}</div></td>
                                                    <td class="px-3 py-3"><span class="inline-flex rounded border px-2 py-1 font-semibold" :class="actionStatusClasses[action.status]">{{ actionStatusLabels[action.status] || action.status }}</span></td>
                                                    <td class="whitespace-nowrap px-3 py-3"><span class="rounded border border-sky-200 bg-sky-50 px-2 py-1.5 font-semibold text-sky-800">{{ action.start_date ? formatDate(action.start_date) : '--' }}</span></td>
                                                    <td class="whitespace-nowrap px-3 py-3"><span class="rounded border border-amber-200 bg-amber-50 px-2 py-1.5 font-semibold text-amber-800">{{ action.due_date ? formatDate(action.due_date) : '--' }}</span></td>
                                                    <td class="whitespace-nowrap px-3 py-3"><span class="rounded border border-violet-200 bg-violet-50 px-2 py-1.5 font-semibold text-violet-800">{{ action.actual_start_date ? formatDate(action.actual_start_date) : '--' }}</span></td>
                                                    <td class="whitespace-nowrap px-3 py-3"><span class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1.5 font-semibold text-emerald-800">{{ action.actual_end_date ? formatDate(action.actual_end_date) : '--' }}</span></td>
                                                    <td class="px-3 py-3"><div class="flex items-center gap-2"><div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full" :class="progressColor(action.progress)" :style="{ width: `${action.progress}%` }" /></div><strong class="text-slate-800">{{ action.progress }}%</strong></div></td>
                                                    <td class="whitespace-nowrap px-3 py-3 text-right"><button type="button" class="inline-flex items-center gap-1.5 rounded border border-blue-200 bg-blue-50 px-2.5 py-1.5 font-semibold text-blue-700 hover:bg-blue-100" @click="expandedConsultationActionId = expandedConsultationActionId === action.id ? null : action.id"><EyeOff v-if="expandedConsultationActionId === action.id" class="h-3.5 w-3.5" aria-hidden="true" /><Eye v-else class="h-3.5 w-3.5" aria-hidden="true" />{{ expandedConsultationActionId === action.id ? 'Masquer' : 'Détails' }}</button></td>
                                                </tr>
                                                <tr v-if="expandedConsultationActionId === action.id" class="border-t border-blue-200 bg-[#f0f5fc]">
                                                    <td colspan="9" class="p-3 sm:p-4">
                                                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3">
                                                            <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-wide text-blue-700">Détail de l’action</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ action.title }}</p></div>
                                                            <div class="flex flex-wrap gap-2"><button v-if="Number(editingActionId) !== Number(action.id)" type="button" class="rounded-[3px] border border-blue-200 bg-white px-3 py-2 font-semibold text-blue-700 hover:bg-blue-50" @click="openActionProgress(action)">✎ Modifier le suivi</button><button type="button" class="rounded-[3px] border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="Number(duplicatingActionId) === Number(action.id)" @click="duplicateAction(action)">{{ Number(duplicatingActionId) === Number(action.id) ? 'Duplication...' : '⧉ Dupliquer' }}</button></div>
                                                        </div>
                                                        <div class="mt-3 grid items-start gap-3 lg:grid-cols-2">
                                                            <section class="min-w-0 border border-slate-200 bg-white p-3">
                                                                <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Description</p>
                                                                <p class="mt-2 whitespace-pre-line break-words text-xs leading-5 text-slate-800">{{ showValue(action.description) }}</p>
                                                            </section>
                                                            <section class="min-w-0 border border-violet-200 bg-violet-50/40 p-3">
                                                                <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-xs font-bold text-violet-800">Commentaires ({{ action.comments.length }})</p><button v-if="Number(commentingActionId) !== Number(action.id)" type="button" class="rounded-[3px] border border-violet-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-100" @click="openActionComment(action)">+ Ajouter</button></div>
                                                                <div v-if="action.comments.length" class="mt-3 space-y-2">
                                                                    <div v-for="comment in action.comments" :key="comment.id" class="border border-violet-100 bg-white p-3">
                                                                        <div class="flex flex-wrap items-center justify-between gap-2"><div><strong class="text-xs text-slate-800">{{ comment.user?.name || '--' }}</strong><span class="ml-2 text-[10px] text-slate-500">{{ formatDateTime(comment.created_at) }}</span></div><div v-if="Number(comment.user_id) === Number(currentUserId) && Number(editingCommentId) !== Number(comment.id)" class="flex gap-2"><button type="button" class="text-xs font-semibold text-blue-700" @click="openCommentEdit(action, comment)">Modifier</button><button type="button" class="text-xs font-semibold text-rose-700 disabled:opacity-50" :disabled="Number(deletingCommentId) === Number(comment.id)" @click="removeActionComment(comment)">Supprimer</button></div></div>
                                                                        <p class="mt-1 whitespace-pre-line break-words text-xs text-slate-700">{{ comment.content }}</p>
                                                                    </div>
                                                                </div>
                                                                <div v-else class="mt-3 border border-violet-100 bg-white px-3 py-3 text-xs text-slate-500">--</div>
                                                                <form v-if="Number(commentingActionId) === Number(action.id)" class="mt-3 grid gap-2" @submit.prevent="submitActionComment"><textarea v-model="actionCommentForm.content" required rows="3" maxlength="10000" class="w-full rounded-[3px] border-violet-200 bg-white text-sm" placeholder="Écrire un commentaire..."></textarea><InputError :message="actionCommentForm.errors.content" /><div class="flex justify-end gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-1.5 text-xs" @click="cancelActionComment">Annuler</button><button type="submit" class="bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60" :disabled="actionCommentForm.processing">{{ editingCommentId ? 'Enregistrer' : 'Publier' }}</button></div></form>
                                                            </section>
                                                        </div>
                                                        <div class="mt-3">
                                            <form v-if="Number(editingActionId) === Number(action.id)" class="mt-4 grid gap-3 rounded border border-blue-100 bg-blue-50/60 p-3" @submit.prevent="submitActionProgress(action)">
                                                <div class="flex items-center justify-between gap-3">
                                                    <label :for="`action-progress-${action.id}`" class="text-xs font-bold text-slate-700">Nouvelle progression</label>
                                                    <div class="flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 shadow-sm">
                                                        <input :id="`action-progress-${action.id}`" v-model.number="actionProgressForm.progress" type="number" min="0" max="100" required class="w-14 border-0 p-0 text-right text-sm font-black text-slate-900 focus:ring-0" />
                                                        <span class="text-xs font-bold text-slate-500">%</span>
                                                    </div>
                                                </div>
                                                <input v-model.number="actionProgressForm.progress" type="range" min="0" max="100" class="w-full accent-blue-600" />
                                                <InputError :message="actionProgressForm.errors.progress" />
                                                <div class="grid gap-3 border-t border-blue-100 pt-3 sm:grid-cols-2 xl:grid-cols-4">
                                                    <label class="grid gap-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">Début prévu<input v-model="actionProgressForm.start_date" type="date" class="rounded border-slate-200 bg-white text-xs normal-case tracking-normal focus:border-blue-500 focus:ring-blue-500" /><InputError :message="actionProgressForm.errors.start_date" /></label>
                                                    <label class="grid gap-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">Fin prévue<input v-model="actionProgressForm.due_date" type="date" class="rounded border-slate-200 bg-white text-xs normal-case tracking-normal focus:border-blue-500 focus:ring-blue-500" /><InputError :message="actionProgressForm.errors.due_date" /></label>
                                                    <label class="grid gap-1 text-[10px] font-bold uppercase tracking-wide text-emerald-600">Début réel<input v-model="actionProgressForm.actual_start_date" type="date" class="rounded border-emerald-200 bg-white text-xs normal-case tracking-normal focus:border-emerald-500 focus:ring-emerald-500" /><InputError :message="actionProgressForm.errors.actual_start_date" /></label>
                                                    <label class="grid gap-1 text-[10px] font-bold uppercase tracking-wide text-emerald-600">Fin réelle<input v-model="actionProgressForm.actual_end_date" type="date" class="rounded border-emerald-200 bg-white text-xs normal-case tracking-normal focus:border-emerald-500 focus:ring-emerald-500" /><InputError :message="actionProgressForm.errors.actual_end_date" /></label>
                                                </div>
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="rounded border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50" :disabled="actionProgressForm.processing" @click="cancelActionProgress">Annuler</button>
                                                    <button type="submit" class="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60" :disabled="actionProgressForm.processing">{{ actionProgressForm.processing ? 'Enregistrement...' : 'Enregistrer' }}</button>
                                                </div>
                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="border-t border-slate-200 bg-slate-100 px-3 py-2 text-[11px] text-slate-500 lg:hidden">Faites défiler le tableau horizontalement pour voir toutes les colonnes.</p>
                            </div>
                            <div v-else class="rounded-md border border-dashed border-slate-300 bg-white p-12 text-center"><p class="font-bold text-slate-800">Aucune action dans ce projet</p></div>
                        </div>
                        <div v-else class="grid min-h-80 place-items-center text-center"><div><div class="text-4xl">📁</div><p class="mt-3 font-bold text-slate-800">Sélectionnez un projet</p></div></div>
                    </main>
                </div>
            </div>
        </Modal>

        <Modal :show="planModalOpen" max-width="2xl" @close="closePlanModal">
            <form class="wp-modal grid gap-5 bg-[#f8f9fb] p-6 sm:p-8" @submit.prevent="submitPlan">
                <div><h2 class="text-xl font-bold text-slate-900">{{ editingPlan ? 'Modifier le plan' : 'Nouveau plan d’action' }}</h2><p class="mt-1 text-sm text-slate-500">Définissez les objectifs et la période du plan.</p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700 sm:col-span-2">Nom<input v-model="planForm.name" required class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="planForm.errors.name" /></label>
                    <label v-if="editingPlan" class="grid gap-1.5 text-sm font-medium text-slate-700">Code<input v-model="planForm.code" readonly class="rounded border-slate-200 bg-slate-50 text-slate-500" /><span class="text-xs text-slate-400">Le code reste stable après la création.</span></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Statut<select v-model="planForm.status" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500"><option v-for="status in statuses" :key="status" :value="status">{{ statusLabels[status] }}</option></select><InputError :message="planForm.errors.status" /></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Début<input v-model="planForm.start_date" type="date" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="planForm.errors.start_date" /></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Fin<input v-model="planForm.end_date" type="date" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="planForm.errors.end_date" /></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700 sm:col-span-2">Progression — {{ planForm.progress }}%<input v-model.number="planForm.progress" type="range" min="0" max="100" class="accent-blue-600" /><InputError :message="planForm.errors.progress" /></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700 sm:col-span-2">Description<textarea v-model="planForm.description" rows="4" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500"></textarea><InputError :message="planForm.errors.description" /></label>
                    <label class="flex items-center gap-3 text-sm font-medium text-slate-700"><input v-model="planForm.is_active" type="checkbox" class="rounded border-slate-300 text-blue-600" /> Plan actif</label>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" class="rounded border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700" @click="closePlanModal">Annuler</button><button class="rounded bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60" :disabled="planForm.processing">Enregistrer</button></div>
            </form>
        </Modal>

        <Modal :show="axisModalOpen" max-width="lg" @close="closeAxisModal">
            <form class="wp-modal grid gap-5 bg-[#f8f9fb] p-6" @submit.prevent="submitAxis">
                <div><h2 class="text-xl font-bold text-slate-900">{{ editingAxis ? 'Modifier l’axe' : 'Nouvel axe' }}</h2><p class="mt-1 text-sm text-slate-500">Ajoutez un axe structurant au plan.</p></div>
                <label class="grid gap-1.5 text-sm font-medium text-slate-700">Nom<input v-model="axisForm.name" required class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="axisForm.errors.name" /></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Code<input v-model="axisForm.code" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="axisForm.errors.code" /></label>
                    <label class="grid gap-1.5 text-sm font-medium text-slate-700">Position<input v-model.number="axisForm.position" type="number" min="0" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500" /><InputError :message="axisForm.errors.position" /></label>
                </div>
                <label class="grid gap-1.5 text-sm font-medium text-slate-700">Description<textarea v-model="axisForm.description" rows="3" class="rounded border-slate-200 focus:border-blue-500 focus:ring-blue-500"></textarea><InputError :message="axisForm.errors.description" /></label>
                <label class="flex items-center gap-3 text-sm font-medium text-slate-700"><input v-model="axisForm.is_active" type="checkbox" class="rounded border-slate-300 text-blue-600" /> Axe actif</label>
                <InputError :message="axisForm.errors.axis" />
                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" class="rounded border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-700" @click="closeAxisModal">Annuler</button><button class="rounded bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60" :disabled="axisForm.processing">Enregistrer</button></div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>

<style scoped>
.wp-kpi { border-width:1px; padding:12px 15px; display:flex; flex-direction:column; gap:4px; border-radius:4px; }
.wp-kpi span { color:#475569; font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.wp-kpi strong { color:#0f172a; font-size:24px; line-height:1.25; }
.wp-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:4px; padding:9px 13px; font-size:12px; font-weight:700; white-space:nowrap; min-height:38px; }
.wp-btn-primary { background:#2563eb; color:white; }
.wp-btn-primary:hover { background:#1d4ed8; }
.wp-btn-secondary { background:#fff; border:1px solid #cbd5e1; color:#334155; }
.wp-field { display:block; width:100%; border:1px solid #cbd5e1; background:white; border-radius:4px; padding:8px 10px; font-size:13px; }
.wp-table { width:100%; border-collapse:collapse; font-size:12px; color:#334155; }
.wp-table th { background:#e9eef5; color:#475569; font-size:10px; letter-spacing:.08em; text-transform:uppercase; font-weight:800; text-align:left; padding:13px 12px; white-space:nowrap; }
.wp-table td { padding:11px 12px; border-top:1px solid #e2e8f0; vertical-align:middle; }
.wp-table tbody tr:hover { background:#eef4ff; }
.wp-status { display:inline-block; padding:4px 8px; font-size:11px; font-weight:700; border-radius:3px; white-space:nowrap; }
.wp-icon-btn { border:1px solid #cbd5e1; background:#fff; border-radius:4px; width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; font-size:16px; font-weight:700; }
.wp-icon-btn:hover { background:#eff6ff; border-color:#93c5fd; }
.wp-consultation-content { border-radius: 2px !important; }
.wp-consultation-content :deep(*) { box-sizing: border-box; }
.wp-modal :deep(input), .wp-modal :deep(select), .wp-modal :deep(textarea) { border-radius:4px; }
@media(max-width:640px) { .wp-table th,.wp-table td { padding:10px 9px; } }
</style>

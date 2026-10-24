<script setup>
import AppIcon from '@/Components/AppIcon.vue';
import DashboardProjectModal from '@/Components/DashboardProjectModal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, ChevronsUpDown, LoaderCircle, MoveVertical } from 'lucide-vue-next';
import { computed, defineAsyncComponent, ref, watch } from 'vue';

const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));

const props = defineProps({
    context: { type: Object, required: true },
    stats: { type: Object, required: true },
    actionPlans: { type: Array, default: () => [] },
    projectPortfolios: { type: Object, default: () => ({}) },
    portfolioTab: { type: String, default: 'solutions' },
    projectFilters: { type: Object, default: () => ({}) },
    projectCategories: { type: Array, default: () => [] },
    projectStatuses: { type: Array, default: () => [] },
    projectPriorities: { type: Array, default: () => [] },
    todayPlanning: { type: Array, default: () => [] },
    teamMembers: { type: Array, default: () => [] },
});

const activeProjectTab = ref(props.portfolioTab === 'infrastructure' ? 'infrastructure' : 'solutions');
const projectFiltersForm = ref({
    project_search: props.projectFilters?.project_search ?? '',
    project_status: props.projectFilters?.project_status ?? '',
    project_priority: props.projectFilters?.project_priority ?? '',
    project_category_id: props.projectFilters?.project_category_id ?? '',
    project_sort: props.projectFilters?.project_sort ?? '',
    project_direction: props.projectFilters?.project_direction ?? 'asc',
});
const selectedProjectId = ref(null);
const orderedDashboardProjects = ref([]);
const draggedDashboardProjectId = ref(null);
const dragOverDashboardProjectId = ref(null);
const isSavingDashboardProjectOrder = ref(false);
const selectedChartProjects = ref({});
const activeTeamTab = ref('all');
const teamTaskRows = computed(() => props.teamMembers.flatMap(member =>
    (member.assigned_tasks ?? []).map(task => ({ member, task }))
));
const filteredTeamTasks = computed(() => activeTeamTab.value === 'all'
    ? teamTaskRows.value
    : teamTaskRows.value.filter(row => String(row.member.id) === String(activeTeamTab.value))
);

const visibleProjects = computed(() => props.projectPortfolios?.[activeProjectTab.value] ?? { data: [], links: [], total: 0 });
watch(
    [visibleProjects, activeProjectTab],
    () => {
        orderedDashboardProjects.value = [...(visibleProjects.value.data ?? [])];
    },
    { immediate: true },
);
const canReorderDashboardProjects = computed(() => Boolean(projectFiltersForm.value.project_category_id)
    && projectFiltersForm.value.project_sort === 'position'
    && projectFiltersForm.value.project_direction === 'asc'
    && !projectFiltersForm.value.project_search
    && !projectFiltersForm.value.project_status
    && !projectFiltersForm.value.project_priority);
const dashboardProjectQuery = () => ({
    ...projectFiltersForm.value,
    portfolio: activeProjectTab.value,
});
const applyProjectFilters = () => {
    router.get(route('dashboard'), dashboardProjectQuery(), { preserveState: true, preserveScroll: true, replace: true });
};
const resetProjectFilters = () => {
    projectFiltersForm.value = {
        project_search: '',
        project_status: '',
        project_priority: '',
        project_category_id: '',
        project_sort: '',
        project_direction: 'asc',
    };
    applyProjectFilters();
};
const sortDashboardProjects = (column) => {
    if (projectFiltersForm.value.project_sort === column) {
        projectFiltersForm.value.project_direction = projectFiltersForm.value.project_direction === 'asc' ? 'desc' : 'asc';
    } else {
        projectFiltersForm.value.project_sort = column;
        projectFiltersForm.value.project_direction = 'asc';
    }
    applyProjectFilters();
};
const dashboardSortIcon = (column) => projectFiltersForm.value.project_sort !== column
    ? ChevronsUpDown
    : projectFiltersForm.value.project_direction === 'asc' ? ChevronUp : ChevronDown;
const startDashboardProjectDrag = (event, project) => {
    if (!canReorderDashboardProjects.value || isSavingDashboardProjectOrder.value) {
        event.preventDefault();
        return;
    }
    draggedDashboardProjectId.value = project.id;
    dragOverDashboardProjectId.value = project.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(project.id));
};
const moveDashboardProject = (targetProject) => {
    if (draggedDashboardProjectId.value === null || Number(draggedDashboardProjectId.value) === Number(targetProject.id) || Number(dragOverDashboardProjectId.value) === Number(targetProject.id)) return;
    const sourceIndex = orderedDashboardProjects.value.findIndex((project) => Number(project.id) === Number(draggedDashboardProjectId.value));
    const targetIndex = orderedDashboardProjects.value.findIndex((project) => Number(project.id) === Number(targetProject.id));
    if (sourceIndex < 0 || targetIndex < 0) return;
    const [draggedProject] = orderedDashboardProjects.value.splice(sourceIndex, 1);
    orderedDashboardProjects.value.splice(targetIndex, 0, draggedProject);
    dragOverDashboardProjectId.value = targetProject.id;
};
const cancelDashboardProjectDrag = () => {
    orderedDashboardProjects.value = [...(visibleProjects.value.data ?? [])];
    draggedDashboardProjectId.value = null;
    dragOverDashboardProjectId.value = null;
};
const saveDashboardProjectOrder = () => {
    if (draggedDashboardProjectId.value === null || isSavingDashboardProjectOrder.value) return;
    const firstPosition = Number(visibleProjects.value.from) || 1;
    const positions = orderedDashboardProjects.value.map((project, index) => ({ id: project.id, position: firstPosition + index }));
    draggedDashboardProjectId.value = null;
    dragOverDashboardProjectId.value = null;
    router.patch(route('projects.reorder'), { project_category_id: projectFiltersForm.value.project_category_id, positions }, {
        preserveScroll: true,
        onStart: () => { isSavingDashboardProjectOrder.value = true; },
        onError: cancelDashboardProjectDrag,
        onFinish: () => { isSavingDashboardProjectOrder.value = false; },
    });
};
const selectedProject = computed(() => {
    const projects = [
        ...(props.projectPortfolios?.solutions?.data ?? []),
        ...(props.projectPortfolios?.infrastructure?.data ?? []),
    ];

    return projects.find((project) => Number(project.id) === Number(selectedProjectId.value)) ?? null;
});
const organizationName = computed(() => props.context?.organization?.name ?? 'Organisation');
const departmentName = computed(() => props.context?.department?.name ?? 'Service');
const departmentCode = computed(() => props.context?.department?.code ?? 'WP');
const globalProgress = computed(() => Math.min(100, Math.max(0, Number(props.stats?.department_progress ?? 0))));
const heroActionPlans = computed(() => {
    const findPlan = (keywords) => props.actionPlans.find((plan) => {
        const identity = `${plan.code ?? ''} ${plan.name ?? ''}`.toLocaleUpperCase('fr');

        return keywords.some((keyword) => identity.includes(keyword));
    });
    const infrastructure = findPlan(['INFRA', 'INFRASTRUCTURE']);
    const solutions = findPlan(['SOLUTION', 'SOLUTIONS']);

    return [
        { key: 'infrastructure', label: 'Infrastructure', plan: infrastructure, color: 'from-cyan-400 to-blue-500' },
        { key: 'solutions', label: 'Solutions', plan: solutions, color: 'from-violet-400 to-indigo-500' },
    ].map((item) => ({
        ...item,
        title: item.plan?.name ?? item.label,
        code: item.plan?.code ?? 'Plan non configuré',
        status: item.plan?.status ?? null,
        axesCount: Number(item.plan?.axes_count ?? 0),
        projectsCount: Number(item.plan?.projects_count ?? 0),
        progress: Math.min(100, Math.max(0, Number(item.plan?.progress ?? 0))),
    }));
});
const openTasks = computed(() => Math.max(0, Number(props.stats.tasks ?? 0) - Number(props.stats.completed_tasks ?? 0)));

const kpis = computed(() => [
    { label: 'Plans d’action', value: props.stats.action_plans ?? 0, caption: 'Feuilles de route actives', icon: 'plans', color: 'violet' },
    { label: 'Projets actifs', value: props.stats.active_projects ?? 0, caption: `${props.stats.projects ?? 0} projets au total`, icon: 'projects', color: 'blue' },
    { label: 'Tâches ouvertes', value: openTasks.value, caption: `${props.stats.completed_tasks ?? 0} terminées`, icon: 'tasks', color: 'cyan' },
    { label: 'Points d’attention', value: props.stats.overdue_tasks ?? 0, caption: 'Échéances dépassées', icon: 'activity', color: 'rose' },
]);

const clampProgress = (value) => Math.min(100, Math.max(0, Number(value ?? 0)));
const statusLabel = (status) => ({ draft: 'Brouillon', active: 'Actif', completed: 'Terminé', archived: 'Archivé', planned: 'Planifié', in_progress: 'En cours', on_hold: 'En attente', cancelled: 'Annulé' })[status] ?? status ?? '—';
const statusClass = (status) => ({ draft: 'bg-[#e4e7ed] text-[#394456]', active: 'bg-[#d4f2e5] text-[#046c4e]', completed: 'bg-[#d4f2e5] text-[#046c4e]', archived: 'bg-[#e4e7ed] text-[#394456]', planned: 'bg-[#dce9ff] text-[#1749a5]', in_progress: 'bg-[#fff0c9] text-[#925000]', on_hold: 'bg-[#ffe4cf] text-[#9a3e0a]', cancelled: 'bg-[#ffe0e6] text-[#ad1640]' })[status] ?? 'bg-[#e4e7ed] text-[#394456]';
const priorityLabel = (priority) => ({ low: 'Faible', medium: 'Moyenne', high: 'Haute', critical: 'Critique' })[priority] ?? priority ?? '—';
const priorityClass = (priority) => ({ low: 'bg-[#e4e7ed] text-[#394456]', medium: 'bg-[#dce9ff] text-[#1749a5]', high: 'bg-[#fff0c9] text-[#925000]', critical: 'bg-[#ffe0e6] text-[#ad1640]' })[priority] ?? 'bg-[#e4e7ed] text-[#394456]';
const progressStyle = (progress) => {
    const value = clampProgress(progress);
    const gradient = value >= 80
        ? 'linear-gradient(90deg, #34d399, #10b981)'
        : value >= 50
            ? 'linear-gradient(90deg, #3b82f6, #6366f1)'
            : value >= 25
                ? 'linear-gradient(90deg, #fbbf24, #fb923c)'
                : 'linear-gradient(90deg, #60a5fa, #6366f1)';

    return { width: `${value}%`, background: gradient };
};
const projectChartColors = ['#2563eb', '#7c3aed', '#06b6d4', '#f59e0b', '#ef4444', '#10b981', '#ec4899', '#4f46e5', '#14b8a6', '#f97316', '#8b5cf6', '#0ea5e9', '#84cc16', '#64748b'];
const projectChartColor = (index) => projectChartColors[index % projectChartColors.length];
const projectDurationDays = (project) => Math.max(0, Number(project?.actual_duration_days) || 0);
const durationLabel = (days) => `${days} jour${Number(days) > 1 ? 's' : ''}`;
const planActualDuration = (plan) => plan.projects.reduce((total, project) => total + projectDurationDays(project), 0);
const projectChartHasDuration = (plan) => plan.projects.some((project) => projectDurationDays(project) > 0);
const selectedChartProjectIndex = (plan) => selectedChartProjects.value[plan.id] ?? null;
const selectedChartProject = (plan) => {
    const selectedIndex = selectedChartProjectIndex(plan);

    return selectedIndex === null ? null : plan.projects?.[selectedIndex] ?? null;
};
const selectChartProject = (plan, projectIndex) => {
    selectedChartProjects.value = {
        ...selectedChartProjects.value,
        [plan.id]: selectedChartProjectIndex(plan) === projectIndex ? null : projectIndex,
    };
};
const projectChartSeries = (plan) => {
    return plan.projects.map((project) => projectDurationDays(project));
};
const projectChartOptions = (plan) => {
    return {
        chart: {
            type: 'donut',
            background: 'transparent',
            animations: {
                enabled: true,
                speed: 550,
                animateGradually: { enabled: true, delay: 45 },
                dynamicAnimation: { enabled: true, speed: 400 },
            },
            events: {
                dataPointSelection: (_event, _chartContext, config) => {
                    if (config.dataPointIndex >= 0) {
                        selectChartProject(plan, config.dataPointIndex);
                    }
                },
            },
            toolbar: { show: false },
        },
        colors: plan.projects.map((_project, index) => projectChartColor(index)),
        labels: plan.projects.map((project) => project.name),
        dataLabels: { enabled: false },
        legend: { show: false },
        stroke: { colors: ['#ffffff'], width: 3 },
        plotOptions: {
            pie: {
                expandOnClick: true,
                donut: { size: '70%' },
            },
        },
        states: {
            hover: { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'none' } },
        },
        tooltip: {
            theme: 'light',
            y: {
                formatter: (value) => durationLabel(value),
            },
        },
    };
};
const formatDate = (date) => date ? new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date)) : '—';
const formatTime = (date) => date ? new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(date)) : '--:--';
const initials = (name) => name?.split(' ').filter(Boolean).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('') || '?';
const openProject = (project) => { selectedProjectId.value = project.id; };
const closeProject = () => { selectedProjectId.value = null; };
</script>

<template>
    <Head title="Dashboard - WorkPilot" />

    <AuthenticatedLayout>
        <div class="dashboard-page min-h-screen min-w-0">
            <div class="mx-auto grid max-w-[1680px] gap-4 px-4 py-5 sm:px-6 lg:px-8 lg:py-6">
                <section class="dashboard-hero relative overflow-hidden rounded-md border border-[#d5dae3] text-slate-950 shadow-none">
                    <div class="dashboard-grid-pattern absolute inset-0 opacity-0" aria-hidden="true" />
                    <div class="hidden" />
                    <div class="hidden" />

                    <div class="dashboard-hero-content relative grid px-5 py-4 sm:px-7 lg:px-8 lg:py-4">
                        <div class="dashboard-hero-column dashboard-hero-info flex flex-col justify-center">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.16em] text-blue-700"><i class="h-1.5 w-1.5 rounded-full bg-cyan-300 shadow-[0_0_12px_rgba(103,232,249,.9)]"/>{{ organizationName }}</span>
                                <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.16em] text-blue-700">{{ departmentCode }} · {{ departmentName }}</span>
                            </div>
                            <h1 class="mt-3 text-2xl font-black tracking-[-0.04em] text-slate-950 sm:text-3xl">Pilotage <span class="text-blue-700">{{ departmentName }}</span></h1>
                            <p class="mt-3 max-w-md text-sm leading-6 text-slate-500">Avancement, priorités et risques en un seul regard.</p>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <Link :href="route('projects.index')" class="inline-flex items-center gap-2 rounded-md border border-[#d5dae3] bg-[#f8f9fb] px-4 py-2.5 text-xs font-semibold text-slate-800 transition hover:bg-slate-50 motion-reduce:transform-none"><AppIcon name="projects" class="h-4 w-4"/>Voir les projets</Link>
                            </div>
                        </div>

                        <div class="dashboard-hero-column dashboard-hero-radial flex items-center justify-center">
                            <div class="dashboard-score relative grid h-36 w-36 shrink-0 place-items-center rounded-full sm:h-40 sm:w-40" :style="{ '--score': `${globalProgress * 3.6}deg` }">
                                <div class="absolute inset-[11px] rounded-full bg-[#f8f9fb] shadow-inner" />
                                <div class="absolute inset-[18px] rounded-full border border-white/[0.06]" />
                                <div class="relative text-center"><span class="block text-4xl font-black tracking-[-0.06em]">{{ globalProgress }}<small class="text-base text-blue-700">%</small></span><span class="mt-1 block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-600">Global</span></div>
                            </div>
                        </div>


                    </div>
                </section>

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="kpi in kpis" :key="kpi.label" class="dashboard-kpi group relative flex min-h-[124px] overflow-hidden rounded-md border border-slate-200 bg-[#f8f9fb] p-4 shadow-none transition duration-200 hover:border-slate-400 motion-reduce:transform-none">
                        <div class="relative flex w-full items-start justify-between gap-4"><div class="flex min-w-0 flex-col"><p class="truncate text-[10px] font-black uppercase tracking-[0.13em] text-slate-500">{{ kpi.label }}</p><p class="mt-2 text-[2rem] font-bold leading-none tracking-[-0.05em] text-slate-950">{{ kpi.value }}</p><p class="mt-auto pt-2 text-xs font-semibold text-slate-500">{{ kpi.caption }}</p></div><span class="grid h-10 w-10 shrink-0 place-items-center rounded-md transition duration-200" :class="{ violet: 'bg-violet-50 text-violet-600', blue: 'bg-[#e4edff] text-[#1d4ed8]', cyan: 'bg-cyan-50 text-cyan-600', rose: 'bg-rose-50 text-rose-600' }[kpi.color]"><AppIcon :name="kpi.icon" class="h-[18px] w-[18px]" :stroke-width="2"/></span></div>
                    </article>
                </section>

                                 <section class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb]">
                     <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[#d5dae3] px-5 py-4 sm:px-6">
                         <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-700">Stratégie</p><h2 class="mt-1 text-lg font-semibold text-slate-900">Progression des plans d’action</h2><p class="mt-1 text-xs text-slate-500">Suivi consolidé des axes et des projets</p></div>
                         <Link :href="route('action-plans.index')" class="text-xs font-semibold text-blue-700 hover:underline">Tous les plans →</Link>
                     </header>
                     <div class="overflow-x-auto">
                         <table class="w-full min-w-[700px] border-collapse text-left">
                             <thead class="bg-[#e9edf3] text-[11px] font-semibold uppercase tracking-wide text-slate-600"><tr><th class="px-5 py-3">Plan d’action</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3 text-center">Axes</th><th class="px-4 py-3 text-center">Projets</th><th class="w-[26%] px-5 py-3">Progression</th></tr></thead>
                             <tbody class="divide-y divide-[#dfe4ec]">
                                 <tr v-for="plan in actionPlans" :key="plan.id" class="transition hover:bg-[#eef2f8]">
                                     <td class="px-5 py-4 text-sm font-semibold text-slate-900">{{ plan.name }}</td>
                                     <td class="px-4 py-4 text-xs font-medium text-slate-600">{{ plan.code || '—' }}</td>
                                     <td class="px-4 py-4"><span class="inline-flex rounded-md px-2.5 py-1 text-[11px] font-semibold" :class="statusClass(plan.status)">{{ statusLabel(plan.status) }}</span></td>
                                     <td class="px-4 py-4 text-center text-sm text-slate-700">{{ plan.axes_count ?? 0 }}</td>
                                     <td class="px-4 py-4 text-center text-sm text-slate-700">{{ plan.projects_count ?? plan.projects?.length ?? 0 }}</td>
                                     <td class="px-5 py-4"><div class="flex items-center gap-3"><div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-[#dce4f0]"><div class="h-full rounded-full bg-blue-600" :style="{ width: `${clampProgress(plan.progress)}%` }" /></div><strong class="w-11 text-right text-xs tabular-nums text-slate-900">{{ clampProgress(plan.progress) }}%</strong></div></td>
                                 </tr>
                                 <tr v-if="!actionPlans.length"><td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">Aucun plan d’action disponible.</td></tr>
                             </tbody>
                         </table>
                     </div>
                 </section>

                <!-- Détail graphique des projets par plan d’action -->
                <section class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb]">
                    <header class="border-b border-[#d5dae3] px-5 py-4 sm:px-6">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-700">Analyse détaillée</p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900">Durée réelle des projets par plan d’action</h2>
                        <p class="mt-1 text-xs text-slate-500">Comparaison entre la première date de début réel et la dernière date de fin réelle des actions de chaque projet.</p>
                    </header>
                    <div v-if="actionPlans.some(plan => plan.projects?.length)" class="grid gap-4 p-4 lg:grid-cols-2 sm:p-5">
                        <article v-for="plan in actionPlans.filter(item => item.projects?.length)" :key="plan.id" class="min-w-0 overflow-hidden rounded-md border border-[#dbe2ec] bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm font-bold text-slate-900">{{ plan.name }}</h3>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ plan.projects.length }} projets · {{ plan.code || 'Sans code' }}</p>
                                </div>
                                <span class="rounded-md bg-blue-50 px-3 py-1.5 text-sm font-bold text-blue-700">{{ durationLabel(planActualDuration(plan)) }}</span>
                            </div>
                            <div class="grid gap-3 p-4 xl:grid-cols-[210px_minmax(0,1fr)] xl:items-center">
                                <div class="relative mx-auto w-full max-w-[230px]">
                                    <VueApexCharts v-if="projectChartHasDuration(plan)" type="donut" height="225" :series="projectChartSeries(plan)" :options="projectChartOptions(plan)" />
                                    <div v-else class="grid h-[225px] place-items-center rounded-full border border-dashed border-slate-300 bg-slate-50 px-5 text-center text-xs font-semibold text-slate-500">Aucune durée réelle complète</div>
                                </div>
                                <div class="min-w-0 space-y-1.5">
                                    <button v-for="(project, index) in plan.projects" :key="project.id ?? index" type="button" class="flex w-full items-center gap-2 rounded-md border px-2.5 py-2 text-left transition hover:bg-blue-50" :class="selectedChartProjectIndex(plan) === index ? 'border-blue-300 bg-blue-50' : 'border-transparent'" @click="selectChartProject(plan, index)">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: projectChartColor(index) }" />
                                        <span class="min-w-0 flex-1 truncate text-xs font-medium text-slate-700" :title="project.name">{{ project.name }}</span>
                                        <span class="shrink-0 text-xs font-bold tabular-nums" :class="projectDurationDays(project) ? 'text-slate-900' : 'text-slate-400'">{{ projectDurationDays(project) ? durationLabel(projectDurationDays(project)) : 'Dates incomplètes' }}</span>
                                    </button>
                                </div>
                            </div>
                            <div v-if="selectedChartProject(plan)" class="flex flex-wrap items-center justify-between gap-2 border-t border-blue-100 bg-blue-50 px-4 py-3">
                                <span class="min-w-0 truncate text-xs font-semibold text-blue-900">{{ selectedChartProject(plan).name }}</span>
                                <span class="text-xs font-bold text-blue-700">{{ formatDate(selectedChartProject(plan).actual_start_date) }} → {{ formatDate(selectedChartProject(plan).actual_end_date) }} · {{ durationLabel(projectDurationDays(selectedChartProject(plan))) }}</span>
                                <button type="button" class="text-xs font-semibold text-blue-700 underline" @click="selectChartProject(plan, selectedChartProjectIndex(plan))">Tout afficher</button>
                            </div>
                        </article>
                    </div>
                    <div v-else class="px-5 py-10 text-center text-sm text-slate-500">Aucun projet détaillé disponible pour les graphiques.</div>
                </section>

               <section class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb] shadow-none">
                    <header class="grid gap-4 border-b border-slate-100 px-5 py-5 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                        <div><p class="text-[10px] font-black uppercase tracking-[0.2em] text-violet-600">Portefeuille</p><h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Projets prioritaires</h2></div>
                        <div class="grid w-full grid-cols-2 gap-1 rounded bg-[#e8edf3] p-1 sm:w-fit">
                            <button v-for="tab in [{ key: 'solutions', label: 'Solutions' }, { key: 'infrastructure', label: 'Infrastructure' }]" :key="tab.key" type="button" class="flex min-w-0 items-center justify-center gap-2 whitespace-nowrap rounded-md px-3 py-2.5 text-xs font-black transition sm:min-w-[130px]" :class="activeProjectTab === tab.key ? 'bg-white text-blue-600 shadow-sm ring-1 ring-slate-200/70' : 'text-slate-500 hover:bg-white/60 hover:text-slate-700'" @click="activeProjectTab = tab.key">
                                <span class="truncate">{{ tab.label }}</span>
                                <span class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full px-1.5 text-[10px] font-black" :class="activeProjectTab === tab.key ? 'bg-[#dce9ff] text-[#1749a5]' : 'bg-slate-200 text-slate-600'">{{ projectPortfolios?.[tab.key]?.total ?? 0 }}</span>
                            </button>
                        </div>
                    </header>
                    <form class="grid grid-cols-1 items-end gap-3 border-b border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-[minmax(280px,1.35fr)_220px_220px_250px_auto]" @submit.prevent="applyProjectFilters">
                        <label class="block min-w-0 text-xs font-semibold text-slate-600">Rechercher<input v-model="projectFiltersForm.project_search" type="search" placeholder="Nom ou code du projet..." class="mt-1 block h-11 w-full rounded-[4px] border-slate-300 bg-white px-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-blue-500 focus:ring-blue-500" /></label>
                        <label class="block min-w-0 text-xs font-semibold text-slate-600">Statut<select v-model="projectFiltersForm.project_status" class="mt-1 block h-11 w-full rounded-[4px] border-slate-300 bg-white py-0 pl-3 pr-9 text-sm text-slate-800 focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les statuts</option><option v-for="status in projectStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                        <label class="block min-w-0 text-xs font-semibold text-slate-600">Niveau<select v-model="projectFiltersForm.project_priority" class="mt-1 block h-11 w-full rounded-[4px] border-slate-300 bg-white py-0 pl-3 pr-9 text-sm text-slate-800 focus:border-blue-500 focus:ring-blue-500"><option value="">Tous les niveaux</option><option v-for="priority in projectPriorities" :key="priority" :value="priority">{{ priorityLabel(priority) }}</option></select></label>
                        <label class="block min-w-0 text-xs font-semibold text-slate-600">Catégorie<select v-model="projectFiltersForm.project_category_id" class="mt-1 block h-11 w-full rounded-[4px] border-slate-300 bg-white py-0 pl-3 pr-9 text-sm text-slate-800 focus:border-blue-500 focus:ring-blue-500"><option value="">Toutes les catégories</option><option v-for="category in projectCategories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                        <div class="flex h-11 items-stretch gap-2 sm:col-span-2 xl:col-span-1">
                            <button type="submit" class="inline-flex flex-1 items-center justify-center rounded-[4px] bg-blue-600 px-4 text-sm font-bold text-white transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Filtrer</button>
                            <button type="button" class="inline-flex items-center justify-center rounded-[4px] border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50" @click="resetProjectFilters">Réinitialiser</button>
                        </div>
                    </form>
                    <div v-if="visibleProjects.data?.length" class="px-3 pb-4 pt-3 sm:px-5">
                        <div class="mb-2 flex items-center justify-between gap-3 rounded border border-blue-100 bg-blue-50 px-3 py-2 text-[11px] text-blue-800">
                            <span v-if="canReorderDashboardProjects">Glissez les projets par la poignée pour modifier leur priorité.</span>
                            <span v-else>Sélectionnez une catégorie et triez « Priorité » par ordre croissant pour activer le classement.</span>
                            <span v-if="isSavingDashboardProjectOrder" class="inline-flex items-center gap-1.5 font-bold"><LoaderCircle class="h-3.5 w-3.5 animate-spin" /> Enregistrement…</span>
                        </div>
                        <div class="overflow-x-auto rounded border border-[#d5dae3]" role="region" aria-label="Tableau des projets prioritaires" tabindex="0">
                            <table class="w-[2180px] min-w-[2180px] table-fixed border-collapse text-left text-xs">
                                <colgroup><col class="w-[125px]"/><col class="w-[250px]"/><col class="w-[255px]"/><col class="w-[180px]"/><col class="w-[220px]"/><col class="w-[190px]"/><col class="w-[185px]"/><col class="w-[115px]"/><col class="w-[105px]"/><col class="w-[175px]"/><col class="w-[135px]"/><col class="w-[95px]"/><col class="w-[130px]"/></colgroup>
                                <thead class="bg-[#e8edf3] text-[11px] font-bold uppercase tracking-[0.06em] text-[#46556a]">
                                    <tr>
                                        <th v-for="column in [{key:'position',label:'Priorité'},{key:'name',label:'Projet'},{key:'action_plan',label:'Plan d’action'},{key:'axis',label:'Établissement'},{key:'departments',label:'Départements'},{key:'category',label:'Catégorie'},{key:'manager',label:'Responsable'},{key:'status',label:'Statut'},{key:'priority',label:'Niveau'},{key:'progress',label:'Progression'},{key:'due_date',label:'Échéance'},{key:'actions_count',label:'Actions'}]" :key="column.key" scope="col" class="px-3 py-3"><button type="button" class="inline-flex items-center gap-1.5" @click="sortDashboardProjects(column.key)">{{ column.label }}<component :is="dashboardSortIcon(column.key)" class="h-3.5 w-3.5 text-blue-600" /></button></th>
                                        <th scope="col" class="px-3 py-3 text-right">Gestion</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#e1e6ee] bg-[#f8f9fb]">
                                    <tr v-for="project in orderedDashboardProjects" :key="project.id" class="transition-colors hover:bg-[#eaf0f8]" :class="[Number(draggedDashboardProjectId) === Number(project.id) ? 'bg-blue-100 opacity-60' : '', Number(dragOverDashboardProjectId) === Number(project.id) && Number(draggedDashboardProjectId) !== Number(project.id) ? 'border-t-2 border-blue-500' : '']" @dragover.prevent="moveDashboardProject(project)" @drop.prevent="saveDashboardProjectOrder">
                                        <td class="px-3 py-3 font-bold text-blue-700"><div class="flex items-center gap-2"><button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded border border-blue-200 bg-blue-50 text-blue-600" :class="canReorderDashboardProjects && !isSavingDashboardProjectOrder ? 'cursor-grab hover:bg-blue-100 active:cursor-grabbing' : 'cursor-not-allowed opacity-40'" :draggable="canReorderDashboardProjects && !isSavingDashboardProjectOrder" :title="canReorderDashboardProjects ? 'Glisser pour modifier la priorité' : 'Sélectionnez une catégorie pour réorganiser'" @dragstart="startDashboardProjectDrag($event, project)" @dragend="draggedDashboardProjectId !== null ? cancelDashboardProjectDrag() : null"><MoveVertical class="h-4 w-4" /></button><span>{{ project.position || '—' }}</span></div></td>
                                        <td class="px-3 py-3 font-semibold text-[#172033]"><span class="block truncate" :title="project.name">{{ project.name }}</span></td>
                                        <td class="px-3 py-3 text-[#334155]"><span class="block truncate" :title="project.action_plan?.name || '—'">{{ project.action_plan?.name || '—' }}</span></td>
                                        <td class="px-3 py-3 text-[#475569]"><span class="block truncate" :title="project.axis?.name || '—'">{{ project.axis?.name || '—' }}</span></td>
                                        <td class="px-3 py-3"><div class="flex flex-wrap gap-1"><span v-for="projectDepartment in project.departments" :key="projectDepartment.id" class="rounded-full bg-cyan-50 px-2 py-1 text-[10px] font-bold text-cyan-800">{{ projectDepartment.name }}</span></div></td>
                                        <td class="px-3 py-3"><p class="truncate font-semibold text-slate-800">{{ project.category?.name || '—' }}</p><p class="truncate text-[10px] text-slate-500">Chef : {{ project.category?.manager?.name || '—' }}</p></td>
                                        <td class="px-3 py-3"><span class="block truncate" :title="project.manager?.name || 'Non assigné'">{{ project.manager?.name || 'Non assigné' }}</span></td>
                                        <td class="px-3 py-3"><span class="inline-flex whitespace-nowrap rounded px-2 py-1 text-[11px] font-semibold" :class="statusClass(project.status)">{{ statusLabel(project.status) }}</span></td>
                                        <td class="px-3 py-3"><span class="inline-flex rounded px-2 py-1 text-[11px] font-semibold" :class="priorityClass(project.priority)">{{ priorityLabel(project.priority) }}</span></td>
                                        <td class="px-3 py-3"><div class="flex items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden rounded-full bg-[#d9e1ed]"><div class="h-full rounded-full bg-[#2563eb]" :style="{ width: `${clampProgress(project.progress)}%` }" /></div><span class="w-9 text-right font-bold tabular-nums text-[#172033]">{{ clampProgress(project.progress) }}%</span></div></td>
                                        <td class="whitespace-nowrap px-3 py-3 font-medium text-[#475569]">{{ formatDate(project.due_date) }}</td>
                                        <td class="px-3 py-3 text-center font-bold text-blue-700">{{ project.actions_count ?? project.actions?.length ?? 0 }}</td>
                                        <td class="px-3 py-3 text-right"><button type="button" class="inline-flex items-center gap-1 rounded border border-[#b9c9e1] bg-[#edf3ff] px-3 py-1.5 font-semibold text-[#1749a5] transition hover:bg-[#dbe8ff]" :aria-label="`Consulter le projet ${project.name}`" @click="openProject(project)"><AppIcon name="projects" class="h-3.5 w-3.5" /> Voir</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-[11px] text-[#64748b] lg:hidden">Faites défiler le tableau horizontalement pour voir toutes les colonnes.</p>
                    </div>
                    <div v-else class="px-6 py-14 text-center text-sm font-medium text-slate-400">Aucun projet disponible dans ce portefeuille.</div>
                    <footer v-if="visibleProjects.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4"><p class="text-xs font-bold text-slate-400">{{ visibleProjects.from }}–{{ visibleProjects.to }} sur {{ visibleProjects.total }}</p><div class="flex flex-wrap gap-1"><Link v-for="link in visibleProjects.links" :key="link.label" :href="link.url || '#'" preserve-scroll preserve-state class="rounded-md border px-3 py-1.5 text-xs font-bold transition" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:text-blue-600', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label"/></div></footer>
                </section>

                <section>
                    <article class="overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb] shadow-none">
                        <header class="flex items-center justify-between border-b border-slate-100 px-5 py-5 sm:px-6"><div><p class="text-[10px] font-black uppercase tracking-[0.2em] text-cyan-600">Aujourd’hui</p><h2 class="mt-1 text-xl font-semibold tracking-tight text-slate-950">Mon planning</h2><p class="mt-1 text-xs text-slate-500">Les tâches planifiées qui vous sont affectées.</p></div><Link :href="route('planning.index')" class="text-xs font-black text-blue-600">Voir mon planning →</Link></header>
                        <div v-if="todayPlanning.length" class="px-5 py-3 sm:px-6"><div v-for="planning in todayPlanning.slice(0, 6)" :key="planning.id" class="relative border-l border-cyan-200 py-3 pl-5"><span class="absolute -left-[5px] top-5 h-2.5 w-2.5 rounded-full border-2 border-white bg-cyan-500 shadow-[0_0_0_3px_rgba(6,182,212,.1)]"/><div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-wide text-cyan-600">{{ formatTime(planning.start_at) }} — {{ formatTime(planning.end_at) }}</p><p class="mt-1 truncate text-sm font-bold text-slate-900">{{ planning.task?.title || 'Tâche' }}</p><p v-if="planning.task?.project" class="mt-1 truncate text-[11px] text-slate-500">{{ planning.task.project.name }}</p></div><div class="flex shrink-0 items-center gap-3"><span v-if="planning.task?.status" class="rounded px-2 py-1 text-[10px] font-bold" :style="{ backgroundColor: `${planning.task.status.color || '#64748b'}18`, color: planning.task.status.color || '#475569' }">{{ planning.task.status.name }}</span><span class="text-xs font-black text-blue-700">{{ clampProgress(planning.task?.progress) }}%</span></div></div></div></div>
                        <div v-else class="grid place-items-center px-6 py-14 text-center"><span class="grid h-12 w-12 place-items-center rounded-md bg-cyan-50 text-cyan-600"><AppIcon name="sessions" class="h-6 w-6"/></span><p class="mt-3 text-sm font-bold text-slate-600">Aucune tâche planifiée aujourd’hui</p><p class="mt-1 text-xs text-slate-400">Vous n’avez aucune tâche affectée dans votre planning du jour.</p></div>
                    </article>
                </section>

                <section class="mb-6 overflow-hidden rounded-md border border-[#d5dae3] bg-[#f8f9fb]">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-500">Suivi de l’équipe</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-900">Tâches par utilisateur</h2>
                            <p class="mt-1 text-xs text-slate-500">Vue consolidée des tâches et de leur avancement.</p>
                        </div>
                        <span class="rounded-md bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600">{{ teamMembers.length }} utilisateurs</span>
                    </header>

                    <div v-if="teamMembers.length">
                        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-3 sm:px-6" aria-label="Filtrer les tâches par utilisateur">
                            <button v-for="tab in [{ id: 'all', name: 'Tous', count: teamTaskRows.length }, ...teamMembers.map(member => ({ id: String(member.id), name: member.name, count: member.assigned_tasks?.length ?? 0 }))]"
                                :key="tab.id" type="button" :aria-pressed="activeTeamTab === tab.id"
                                class="shrink-0 whitespace-nowrap border-b-2 px-3 py-3 text-xs font-medium transition-colors"
                                :class="activeTeamTab === tab.id ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'"
                                @click="activeTeamTab = tab.id">
                                {{ tab.name }} <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">{{ tab.count }}</span>
                            </button>
                        </nav>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] border-collapse text-left">
                                <thead class="bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold">Collaborateur</th>
                                        <th class="px-4 py-3 font-semibold">Tâche</th>
                                        <th class="px-4 py-3 font-semibold">Projet</th>
                                        <th class="px-4 py-3 font-semibold">Statut</th>
                                        <th class="px-4 py-3 font-semibold">Progression</th>
                                        <th class="px-4 py-3 font-semibold">Échéance</th>
                                        <th class="px-4 py-3 font-semibold"><span class="sr-only">Action</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(row, index) in filteredTeamTasks" :key="`${row.member.id}-${row.task.id}-${index}`" class="hover:bg-slate-50/80">
                                        <td class="px-5 py-3.5"><div class="flex items-center gap-2.5"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-[10px] font-semibold text-white" :class="['bg-blue-600','bg-violet-600','bg-teal-600','bg-amber-600'][teamMembers.findIndex(m => m.id === row.member.id) % 4]">{{ initials(row.member.name) }}</span><span class="max-w-40 truncate text-xs font-medium text-slate-800" :title="row.member.name">{{ row.member.name }}</span></div></td>
                                        <td class="max-w-56 px-4 py-3.5"><span class="block truncate text-xs font-medium text-slate-900" :title="row.task.title">{{ row.task.title }}</span></td>
                                        <td class="max-w-56 px-4 py-3.5"><span class="block truncate text-xs text-slate-600" :title="row.task.project?.name ?? 'Sans projet'">{{ row.task.project?.name ?? 'Sans projet' }}</span></td>
                                        <td class="px-4 py-3.5"><span v-if="row.task.status" class="inline-flex whitespace-nowrap rounded-md px-2 py-1 text-[11px] font-medium" :style="{ backgroundColor: `${row.task.status.color || '#64748b'}15`, color: row.task.status.color || '#64748b' }">{{ row.task.status.name }}</span><span v-else class="text-xs text-slate-400">—</span></td>
                                        <td class="px-4 py-3.5"><div class="flex min-w-28 items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :style="progressStyle(row.task.progress)" /></div><span class="w-9 text-right text-xs tabular-nums text-slate-700">{{ clampProgress(row.task.progress) }}%</span></div></td>
                                        <td class="whitespace-nowrap px-4 py-3.5 text-xs text-slate-500">{{ formatDate(row.task.due_at) }}</td>
                                        <td class="px-4 py-3.5"><Link :href="route('tasks.index')" class="text-xs font-medium text-blue-600 hover:underline">Voir</Link></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="!filteredTeamTasks.length" class="border-t border-slate-100 px-6 py-12 text-center text-sm text-slate-500">Aucune tâche assignée{{ activeTeamTab === 'all' ? ' dans ce service' : ' à cet utilisateur' }}.</div>
                        <footer class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 sm:px-6">{{ filteredTeamTasks.length }} tâche(s) affichée(s)</footer>
                    </div>
                    <div v-else class="px-6 py-12 text-center text-sm text-slate-500">Aucun membre dans ce service.</div>
                </section>

                <DashboardProjectModal :show="selectedProjectId !== null" :project="selectedProject" @close="closeProject" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
@property --score {
    syntax: '<angle>';
    inherits: false;
    initial-value: 0deg;
}

.dashboard-page {
    background: #f1f3f6;
}

.dashboard-hero {
    background: #f8f9fb;
}

.dashboard-hero :deep(.text-white) { color: #0f172a !important; }
.dashboard-hero :deep(.text-slate-300),
.dashboard-hero :deep(.text-slate-400) { color: #64748b !important; }
.dashboard-hero :deep(.text-blue-100),
.dashboard-hero :deep(.text-blue-200),
.dashboard-hero :deep(.text-blue-300) { color: #2563eb !important; }
.dashboard-hero :deep(.border-white\/10) { border-color: #dbeafe !important; }
.dashboard-hero :deep(.bg-white\/\[0\.055\]),
.dashboard-hero :deep(.bg-white\/\[0\.07\]) { background-color: #eef1f5 !important; }
.dashboard-hero :deep(.bg-white\/10) { background-color: #e1e5ec !important; }
.dashboard-hero :deep(.bg-slate-950\/95) { background-color: #f8f9fb !important; }

.dashboard-hero-content {
    grid-template-columns: minmax(0, 1fr);
    align-items: center;
}

.dashboard-hero-column {
    min-width: 0;
    padding-block: .65rem;
}

@media (min-width: 768px) and (max-width: 1023px) {
    .dashboard-hero-content {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-hero-radial {
        border-left: 1px solid rgba(226, 232, 240, .9);
    }

    .dashboard-hero-stats {
        grid-column: 1 / -1;
        border-top: 1px solid rgba(226, 232, 240, .9);
    }
}

@media (min-width: 1024px) {
    .dashboard-hero-content {
        grid-template-columns: minmax(0, 1.2fr) minmax(0, .8fr);
        align-items: center;
    }

    .dashboard-hero-column + .dashboard-hero-column {
        border-left: 1px solid rgba(226, 232, 240, .9);
    }
}

@media (min-width: 768px) {
    .dashboard-hero-column {
        padding-inline: 1.5rem;
    }

    .dashboard-hero-info {
        padding-left: 0;
    }

    .dashboard-hero-stats {
        padding-right: 0;
    }
}

@media (max-width: 767px) {
    .dashboard-hero-column + .dashboard-hero-column {
        border-top: 1px solid rgba(226, 232, 240, .9);
    }
}

.dashboard-grid-pattern {
    background-image:
        linear-gradient(rgba(59,130,246,.055) 1px, transparent 1px),
        linear-gradient(90deg, rgba(59,130,246,.055) 1px, transparent 1px);
    background-size: 36px 36px;
    mask-image: linear-gradient(to right, black, transparent 78%);
}

.dashboard-score {
    background: conic-gradient(from -90deg, #60a5fa 0deg, #2563eb var(--score), #dbeafe var(--score));
    box-shadow: none;
}

@media (prefers-reduced-motion: no-preference) {
    .dashboard-score { animation: score-fill 1s cubic-bezier(.2,.8,.2,1) both, score-arrive .45s ease-out both; }
    .project-donut { animation: donut-arrive .75s cubic-bezier(.2,.8,.2,1) both; }
    .dashboard-plan-progress { animation: progress-arrive .9s .15s cubic-bezier(.2,.8,.2,1) both; transform-origin: left; }
    .dashboard-kpi { animation: card-arrive .4s cubic-bezier(.2,.8,.2,1) both; }
    .dashboard-kpi:nth-child(2) { animation-delay: .06s; }
    .dashboard-kpi:nth-child(3) { animation-delay: .12s; }
    .dashboard-kpi:nth-child(4) { animation-delay: .18s; }
}

@keyframes score-fill { from { --score: 0deg; } }
@keyframes score-arrive { from { opacity: 0; transform: scale(.94); } }
@keyframes donut-arrive { from { opacity: 0; transform: scale(.82) rotate(-24deg); } }
@keyframes progress-arrive { from { opacity: 0; transform: scaleX(0); } }
@keyframes card-arrive { from { opacity: 0; transform: translateY(12px); } }
</style>

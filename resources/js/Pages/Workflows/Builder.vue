<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import WorkflowAIModal from "@/Components/Workflows/WorkflowAIModal.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import {
    ArrowLeft,
    Bell,
    Check,
    CheckCircle2,
    CirclePlay,
    Copy,
    Diamond,
    GitBranch,
    Maximize2,
    Palette,
    Plus,
    Redo2,
    Save,
    Send,
    Sparkles,
    Square,
    Trash2,
    Undo2,
    ZoomIn,
    ZoomOut,
} from "lucide-vue-next";

const props = defineProps({
    project: Object,
    workflow: Object,
    canManage: Boolean,
    aiConfigured: Boolean,
});
const editable = computed(
    () => props.canManage && props.workflow.status === "draft",
);
const nodeWidth = 300;
const nodeHeight = 178;
const nodeSize = (node) => ({
    width: Math.max(230, Number(node.data?.width) || nodeWidth),
    height: Math.max(145, Number(node.data?.height) || nodeHeight),
});
const stepNumber = (node) =>
    String(
        [...nodes.value]
            .sort(
                (a, b) =>
                    a.position.y - b.position.y || a.position.x - b.position.x,
            )
            .findIndex((item) => item.id === node.id) + 1,
    ).padStart(2, "0");
const canvasWidth = computed(() =>
    Math.max(
        3600,
        ...nodes.value.map(
            (node) => node.position.x + nodeSize(node).width + 500,
        ),
    ),
);
const canvasHeight = computed(() =>
    Math.max(
        3000,
        ...nodes.value.map(
            (node) => node.position.y + nodeSize(node).height + 500,
        ),
    ),
);
const canvas = ref(null);
const name = ref(props.workflow.name);
const description = ref(props.workflow.description || "");
const nodes = ref(
    props.workflow.nodes.map((node) => ({
        id: node.node_key,
        type: node.type,
        label: node.label,
        position: { x: node.position_x, y: node.position_y },
        data: { ...(node.configuration || {}) },
    })),
);
const edges = ref(
    props.workflow.edges.map((edge) => ({
        id: edge.edge_key,
        source: edge.source_key,
        target: edge.target_key,
        label: edge.label || "",
    })),
);
const selectedNodeId = ref(null);
const selectedEdgeId = ref(null);
const inlineEdit = ref(null);
const inlineValue = ref("");
const editingWorkflowName = ref(false);
const connectingFrom = ref(null);
const zoom = ref(1);
const pan = ref({ x: 80, y: 70 });
const busy = ref(false);
const showAIModal = ref(false);
const errors = ref({});
const saved = ref(true);
const history = ref([]);
const historyIndex = ref(-1);
let dragState = null;
let resizeState = null;
let panState = null;
const currentGraphForAI = computed(() => ({
    nodes: nodes.value.map((node) => ({
        id: node.id,
        type: node.type,
        label: node.label,
        description: node.data.description || "",
    })),
    edges: edges.value.map((edge) => ({
        source: edge.source,
        target: edge.target,
        label: edge.label || "",
    })),
}));

const palette = [
    {
        type: "start",
        label: "Début",
        hint: "Point d'entrée",
        icon: CirclePlay,
        color: "mint",
    },
    {
        type: "task",
        label: "Tâche",
        hint: "Travail à réaliser",
        icon: Square,
        color: "sky",
    },
    {
        type: "approval",
        label: "Validation",
        hint: "Décision humaine",
        icon: CheckCircle2,
        color: "sand",
    },
    {
        type: "condition",
        label: "Condition",
        hint: "Créer une branche",
        icon: Diamond,
        color: "lavender",
    },
    {
        type: "notification",
        label: "Notification",
        hint: "Informer l'équipe",
        icon: Bell,
        color: "aqua",
    },
    {
        type: "end",
        label: "Fin",
        hint: "Fin du processus",
        icon: Check,
        color: "mist",
    },
];
const nodeStyles = {
    start: "border-emerald-300 bg-emerald-50 text-emerald-700",
    task: "border-blue-300 bg-blue-50 text-blue-700",
    approval: "border-amber-300 bg-amber-50 text-amber-700",
    condition: "border-violet-300 bg-violet-50 text-violet-700",
    notification: "border-cyan-300 bg-cyan-50 text-cyan-700",
    end: "border-slate-300 bg-slate-100 text-slate-700",
};
const colorThemes = [
    {
        key: "mint",
        name: "Menthe",
        background: "#F1FCF7",
        icon: "#DDF8EB",
        border: "#A7E8CC",
        accent: "#0F8A68",
    },
    {
        key: "sky",
        name: "Ciel",
        background: "#F2F8FF",
        icon: "#E0EFFF",
        border: "#B5D7FA",
        accent: "#2477C9",
    },
    {
        key: "aqua",
        name: "Aqua",
        background: "#F0FBFC",
        icon: "#DDF6F8",
        border: "#A9E2E7",
        accent: "#138594",
    },
    {
        key: "lavender",
        name: "Lavande",
        background: "#F8F5FF",
        icon: "#EEE7FF",
        border: "#D2C2FA",
        accent: "#7951C8",
    },
    {
        key: "lilac",
        name: "Lilas",
        background: "#FCF6FF",
        icon: "#F4E5FC",
        border: "#DFC0ED",
        accent: "#9A52B6",
    },
    {
        key: "rose",
        name: "Rose",
        background: "#FFF5F8",
        icon: "#FFE4EC",
        border: "#F5BCCC",
        accent: "#C45274",
    },
    {
        key: "peach",
        name: "Pêche",
        background: "#FFF7F2",
        icon: "#FFE8DA",
        border: "#F6C6A9",
        accent: "#C66A35",
    },
    {
        key: "sand",
        name: "Sable",
        background: "#FFFAF0",
        icon: "#FFF0CF",
        border: "#F1D294",
        accent: "#B77918",
    },
    {
        key: "lemon",
        name: "Citron",
        background: "#FFFDEE",
        icon: "#FFF8C9",
        border: "#ECE093",
        accent: "#988014",
    },
    {
        key: "pistachio",
        name: "Pistache",
        background: "#F8FCEF",
        icon: "#EDF7D8",
        border: "#CFE4A4",
        accent: "#668A2E",
    },
    {
        key: "sage",
        name: "Sauge",
        background: "#F5FAF6",
        icon: "#E5F1E8",
        border: "#BCD6C2",
        accent: "#527B5C",
    },
    {
        key: "ice",
        name: "Glace",
        background: "#F3FBFF",
        icon: "#E1F5FF",
        border: "#B9E1F4",
        accent: "#267EAA",
    },
    {
        key: "denim",
        name: "Denim",
        background: "#F4F7FC",
        icon: "#E5ECF8",
        border: "#BECDEA",
        accent: "#4B6595",
    },
    {
        key: "periwinkle",
        name: "Pervenche",
        background: "#F5F6FF",
        icon: "#E8EAFF",
        border: "#C5C9F5",
        accent: "#5C66B7",
    },
    {
        key: "coral",
        name: "Corail",
        background: "#FFF5F3",
        icon: "#FFE4DF",
        border: "#F3BDB4",
        accent: "#BC5F50",
    },
    {
        key: "blush",
        name: "Poudré",
        background: "#FFF8F7",
        icon: "#F8E9E6",
        border: "#E7CAC4",
        accent: "#9A6960",
    },
    {
        key: "latte",
        name: "Latte",
        background: "#FBF8F4",
        icon: "#F1E9DF",
        border: "#DCCDBC",
        accent: "#806A50",
    },
    {
        key: "silver",
        name: "Argent",
        background: "#F8FAFC",
        icon: "#EDF1F5",
        border: "#CFD8E3",
        accent: "#607086",
    },
    {
        key: "mist",
        name: "Brume",
        background: "#F6F8FA",
        icon: "#E8EDF2",
        border: "#C8D2DC",
        accent: "#526373",
    },
    {
        key: "white",
        name: "Blanc",
        background: "#FFFFFF",
        icon: "#F1F5F9",
        border: "#D8E0E9",
        accent: "#475569",
    },
];
const selectedNode = computed(() =>
    nodes.value.find((node) => node.id === selectedNodeId.value),
);
const nodeTheme = (node) =>
    colorThemes.find((theme) => theme.key === node.data.color) ||
    colorThemes.find(
        (theme) =>
            theme.key ===
            palette.find((item) => item.type === node.type)?.color,
    ) ||
    colorThemes[1];
const setNodeColor = (color) => {
    if (!selectedNode.value) return;
    selectedNode.value.data.color = color.key;
    checkpoint();
};
const beginInlineEdit = (event, node, field) => {
    if (!editable.value) return;
    event.stopPropagation();
    inlineEdit.value = { nodeId: node.id, field };
    inlineValue.value =
        field === "label" ? node.label : node.data.description || "";
    nextTick(() =>
        event.currentTarget
            .closest(".workflow-node")
            ?.querySelector(`[data-inline-field="${field}"]`)
            ?.focus(),
    );
};
const commitInlineEdit = () => {
    if (!inlineEdit.value) return;
    const node = nodes.value.find(
        (item) => item.id === inlineEdit.value.nodeId,
    );
    if (node) {
        if (inlineEdit.value.field === "label" && inlineValue.value.trim())
            node.label = inlineValue.value.trim();
        if (inlineEdit.value.field === "description")
            node.data.description = inlineValue.value.trim();
    }
    inlineEdit.value = null;
    checkpoint();
};
const cancelInlineEdit = () => {
    inlineEdit.value = null;
};
const commitWorkflowName = () => {
    editingWorkflowName.value = false;
    if (!name.value.trim()) name.value = props.workflow.name;
    checkpoint();
};
const stateSnapshot = () =>
    JSON.stringify({
        nodes: nodes.value,
        edges: edges.value,
        name: name.value,
        description: description.value,
    });
const checkpoint = () => {
    const snapshot = stateSnapshot();
    if (history.value[historyIndex.value] === snapshot) return;
    history.value = history.value.slice(0, historyIndex.value + 1);
    history.value.push(snapshot);
    historyIndex.value = history.value.length - 1;
    saved.value = false;
};
const restore = (index) => {
    const state = JSON.parse(history.value[index]);
    nodes.value = state.nodes;
    edges.value = state.edges;
    name.value = state.name;
    description.value = state.description;
    historyIndex.value = index;
    selectedNodeId.value = null;
    selectedEdgeId.value = null;
    saved.value = false;
};
const undo = () => historyIndex.value > 0 && restore(historyIndex.value - 1);
const redo = () =>
    historyIndex.value < history.value.length - 1 &&
    restore(historyIndex.value + 1);
const uid = (prefix) =>
    `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
const addNode = (item, x = 260, y = 180) => {
    if (!editable.value) return;
    const node = {
        id: uid("node"),
        type: item.type,
        label: item.label,
        position: { x, y },
        data: { description: "", owner: "", duration: null, color: item.color },
    };
    nodes.value.push(node);
    selectedNodeId.value = node.id;
    selectedEdgeId.value = null;
    checkpoint();
};
const paletteDrag = (event, item) =>
    event.dataTransfer.setData(
        "application/workpilot-workflow",
        JSON.stringify(item),
    );
const dropNode = (event) => {
    const raw = event.dataTransfer.getData("application/workpilot-workflow");
    if (!raw) return;
    const rect = canvas.value.getBoundingClientRect();
    addNode(
        JSON.parse(raw),
        (event.clientX - rect.left + canvas.value.scrollLeft - pan.value.x) /
            zoom.value -
            nodeWidth / 2,
        (event.clientY - rect.top + canvas.value.scrollTop - pan.value.y) /
            zoom.value -
            nodeHeight / 2,
    );
};
const startNodeDrag = (event, node) => {
    if (!editable.value) return;
    event.stopPropagation();
    selectedNodeId.value = node.id;
    selectedEdgeId.value = null;
    dragState = {
        id: node.id,
        startX: event.clientX,
        startY: event.clientY,
        x: node.position.x,
        y: node.position.y,
    };
};
const startResize = (event, node, direction) => {
    if (!editable.value) return;
    event.preventDefault();
    event.stopPropagation();
    selectedNodeId.value = node.id;
    const size = nodeSize(node);
    resizeState = {
        id: node.id,
        direction,
        x: event.clientX,
        y: event.clientY,
        width: size.width,
        height: size.height,
    };
};
const startPan = (event) => {
    if (event.button !== 0) return;
    if (
        event.target.closest(
            ".workflow-node, button, input, textarea, select, a, [data-no-canvas-pan]",
        )
    ) return;

    event.preventDefault();
    selectedNodeId.value = null;
    selectedEdgeId.value = null;
    panState = {
        startX: event.clientX,
        startY: event.clientY,
        x: pan.value.x,
        y: pan.value.y,
    };
};
const pointerMove = (event) => {
    if (resizeState) {
        const node = nodes.value.find((item) => item.id === resizeState.id);
        if (node) {
            if (resizeState.direction.includes("x"))
                node.data.width = Math.max(
                    230,
                    Math.round(
                        resizeState.width +
                            (event.clientX - resizeState.x) / zoom.value,
                    ),
                );
            if (resizeState.direction.includes("y"))
                node.data.height = Math.max(
                    145,
                    Math.round(
                        resizeState.height +
                            (event.clientY - resizeState.y) / zoom.value,
                    ),
                );
        }
        return;
    }
    if (dragState) {
        const node = nodes.value.find((item) => item.id === dragState.id);
        if (node)
            node.position = {
                x: Math.max(
                    0,
                    dragState.x +
                        (event.clientX - dragState.startX) / zoom.value,
                ),
                y: Math.max(
                    0,
                    dragState.y +
                        (event.clientY - dragState.startY) / zoom.value,
                ),
            };
    }
    if (panState)
        pan.value = {
            x: panState.x + event.clientX - panState.startX,
            y: panState.y + event.clientY - panState.startY,
        };
};
const pointerUp = () => {
    if (dragState || resizeState) checkpoint();
    resizeState = null;
    dragState = null;
    panState = null;
};
const connect = (node) => {
    if (!editable.value) return;
    if (!connectingFrom.value) {
        connectingFrom.value = node.id;
        return;
    }
    if (
        connectingFrom.value !== node.id &&
        !edges.value.some(
            (edge) =>
                edge.source === connectingFrom.value && edge.target === node.id,
        )
    ) {
        edges.value.push({
            id: uid("edge"),
            source: connectingFrom.value,
            target: node.id,
            label: "",
        });
        checkpoint();
    }
    connectingFrom.value = null;
};
const pathFor = (edge) => {
    const source = nodes.value.find((node) => node.id === edge.source);
    const target = nodes.value.find((node) => node.id === edge.target);
    if (!source || !target) return "";
    const x1 = source.position.x + nodeSize(source).width / 2,
        y1 = source.position.y + nodeSize(source).height;
    const x2 = target.position.x + nodeSize(target).width / 2,
        y2 = target.position.y;
    const curve = Math.max(70, Math.abs(y2 - y1) * 0.45);
    return `M ${x1} ${y1} C ${x1} ${y1 + curve}, ${x2} ${y2 - curve}, ${x2} ${y2}`;
};
const edgeLabelPosition = (edge) => {
    const source = nodes.value.find((node) => node.id === edge.source),
        target = nodes.value.find((node) => node.id === edge.target);
    return source && target
        ? {
              x:
                  (source.position.x +
                      nodeSize(source).width / 2 +
                      target.position.x +
                      nodeSize(target).width / 2) /
                  2,
              y:
                  (source.position.y +
                      nodeSize(source).height +
                      target.position.y) /
                  2,
          }
        : { x: 0, y: 0 };
};
const removeSelected = () => {
    if (!editable.value) return;
    if (selectedNodeId.value) {
        edges.value = edges.value.filter(
            (edge) =>
                edge.source !== selectedNodeId.value &&
                edge.target !== selectedNodeId.value,
        );
        nodes.value = nodes.value.filter(
            (node) => node.id !== selectedNodeId.value,
        );
        selectedNodeId.value = null;
    } else if (selectedEdgeId.value) {
        edges.value = edges.value.filter(
            (edge) => edge.id !== selectedEdgeId.value,
        );
        selectedEdgeId.value = null;
    }
    checkpoint();
};
const fitView = () => {
    if (!nodes.value.length || !canvas.value) return;
    const rect = canvas.value.getBoundingClientRect();
    const minX = Math.min(...nodes.value.map((node) => node.position.x)),
        minY = Math.min(...nodes.value.map((node) => node.position.y));
    const maxX = Math.max(
            ...nodes.value.map(
                (node) => node.position.x + nodeSize(node).width,
            ),
        ),
        maxY = Math.max(
            ...nodes.value.map(
                (node) => node.position.y + nodeSize(node).height,
            ),
        );
    zoom.value = Math.min(
        1.15,
        Math.max(
            0.35,
            Math.min(
                (rect.width - 120) / (maxX - minX),
                (rect.height - 120) / (maxY - minY),
            ),
        ),
    );
    pan.value = {
        x: (rect.width - (maxX - minX) * zoom.value) / 2 - minX * zoom.value,
        y: (rect.height - (maxY - minY) * zoom.value) / 2 - minY * zoom.value,
    };
};
const applyTemplate = () => {
    if (
        !editable.value ||
        (nodes.value.length > 2 &&
            !confirm("Remplacer le schéma actuel par le modèle standard ?"))
    )
        return;
    nodes.value = [
        {
            id: "start",
            type: "start",
            label: "Demande reçue",
            position: { x: 520, y: 100 },
            data: {},
        },
        {
            id: "analyse",
            type: "task",
            label: "Analyser la demande",
            position: { x: 520, y: 330 },
            data: { duration: 1 },
        },
        {
            id: "validation",
            type: "approval",
            label: "Validation responsable",
            position: { x: 520, y: 560 },
            data: {},
        },
        {
            id: "decision",
            type: "condition",
            label: "Demande validée ?",
            position: { x: 520, y: 790 },
            data: {},
        },
        {
            id: "notify",
            type: "notification",
            label: "Notifier le demandeur",
            position: { x: 520, y: 1020 },
            data: {},
        },
        {
            id: "end",
            type: "end",
            label: "Processus terminé",
            position: { x: 520, y: 1250 },
            data: {},
        },
    ];
    edges.value = [
        { id: "e1", source: "start", target: "analyse", label: "" },
        { id: "e2", source: "analyse", target: "validation", label: "" },
        { id: "e3", source: "validation", target: "decision", label: "" },
        { id: "e4", source: "decision", target: "notify", label: "Oui" },
        { id: "e5", source: "notify", target: "end", label: "" },
    ];
    checkpoint();
    nextTick(fitView);
};
const applyAIProposal = (graph) => {
    nodes.value = graph.nodes;
    edges.value = graph.edges;
    name.value = graph.name || name.value;
    description.value = graph.summary || description.value;
    selectedNodeId.value = null;
    selectedEdgeId.value = null;
    connectingFrom.value = null;
    showAIModal.value = false;
    checkpoint();
    nextTick(fitView);
};
const save = (afterSave) => {
    if (!editable.value) return;
    busy.value = true;
    errors.value = {};
    router.put(
        route("workflows.update", [props.project.id, props.workflow.id]),
        {
            name: name.value,
            description: description.value,
            nodes: nodes.value.map((node) => ({
                id: node.id,
                type: node.type,
                label: node.label,
                position: node.position,
                data: node.data,
            })),
            edges: edges.value.map((edge) => ({
                id: edge.id,
                source: edge.source,
                target: edge.target,
                sourceHandle: null,
                label: edge.label || null,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                saved.value = true;
                afterSave?.();
            },
            onError: (value) => (errors.value = value),
            onFinish: () => (busy.value = false),
        },
    );
};
const publish = () =>
    save(() =>
        router.post(
            route("workflows.publish", [props.project.id, props.workflow.id]),
        ),
    );
const duplicate = () =>
    router.post(
        route("workflows.duplicate", [props.project.id, props.workflow.id]),
    );
const keydown = (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        save();
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "z") {
        event.preventDefault();
        event.shiftKey ? redo() : undo();
    }
    if (
        (event.key === "Delete" || event.key === "Backspace") &&
        !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName)
    )
        removeSelected();
};
onMounted(() => {
    window.addEventListener("mousemove", pointerMove);
    window.addEventListener("mouseup", pointerUp);
    window.addEventListener("keydown", keydown);
    checkpoint();
    saved.value = true;
    nextTick(fitView);
});
onUnmounted(() => {
    window.removeEventListener("mousemove", pointerMove);
    window.removeEventListener("mouseup", pointerUp);
    window.removeEventListener("keydown", keydown);
});
</script>

<template>
    <Head :title="`Workflow · ${workflow.name}`" />
    <AuthenticatedLayout>
        <div
            class="workflow-page-scroll flex h-[calc(100vh-5.25rem)] min-h-[680px] flex-col overflow-x-scroll overflow-y-hidden bg-slate-100"
        >
            <header
                class="grid shrink-0 gap-3 border-b border-slate-200 bg-white px-5 py-3 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-center"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <Link
                        :href="route('workflows.index', project.id)"
                        class="rounded-lg border border-slate-200 p-2 text-slate-600 hover:bg-slate-50"
                        ><ArrowLeft :size="18"
                    /></Link>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <input
                                v-if="editingWorkflowName"
                                v-model="name"
                                class="w-64 rounded-md border-blue-300 px-2 py-1 text-lg font-bold text-slate-900"
                                autofocus
                                @blur="commitWorkflowName"
                                @keydown.enter.prevent="commitWorkflowName"
                                @keydown.esc.prevent="
                                    editingWorkflowName = false
                                "
                            />
                            <h1
                                v-else
                                class="truncate text-lg font-bold text-slate-900"
                                :class="
                                    editable
                                        ? 'cursor-text hover:text-blue-700'
                                        : ''
                                "
                                title="Double-cliquez pour modifier"
                                @dblclick="
                                    editable && (editingWorkflowName = true)
                                "
                            >
                                {{ name }}
                            </h1>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                :class="
                                    workflow.status === 'draft'
                                        ? 'bg-amber-100 text-amber-700'
                                        : 'bg-emerald-100 text-emerald-700'
                                "
                                >{{
                                    workflow.status === "draft"
                                        ? "Brouillon"
                                        : "Publié"
                                }}</span
                            >
                        </div>
                        <p class="truncate text-xs text-slate-500">
                            {{ project.name }} · v{{ workflow.version }}
                        </p>
                    </div>
                </div>
                <div
                    class="flex min-w-0 flex-wrap items-center justify-end gap-2 xl:flex-nowrap"
                >
                    <span
                        class="hidden text-xs sm:block"
                        :class="saved ? 'text-emerald-600' : 'text-amber-600'"
                        >{{
                            saved
                                ? "Modifications enregistrées"
                                : "Modifications non enregistrées"
                        }}</span
                    >
                    <button
                        class="toolbar-btn"
                        :disabled="historyIndex <= 0"
                        @click="undo"
                        title="Annuler"
                    >
                        <Undo2 :size="17" /><span class="hidden xl:inline"
                            >Annuler</span
                        ></button
                    ><button
                        class="toolbar-btn"
                        :disabled="historyIndex >= history.length - 1"
                        @click="redo"
                        title="Rétablir"
                    >
                        <Redo2 :size="17" /><span class="hidden xl:inline"
                            >Rétablir</span
                        >
                    </button>
                    <button
                        v-if="editable"
                        class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:from-blue-700 hover:to-violet-700"
                        @click="showAIModal = true"
                    >
                        <Sparkles :size="16" /> Générer avec IA
                    </button>
                    <button
                        v-if="workflow.status !== 'draft' && canManage"
                        class="toolbar-btn"
                        @click="duplicate"
                    >
                        <Copy :size="17" /><span class="hidden sm:inline"
                            >Dupliquer</span
                        >
                    </button>
                    <button
                        v-if="editable"
                        class="toolbar-btn border-blue-200 text-blue-700"
                        :disabled="busy"
                        @click="save()"
                    >
                        <Save :size="17" /> Enregistrer
                    </button>
                    <button
                        v-if="editable"
                        class="flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"
                        :disabled="busy"
                        @click="publish"
                    >
                        <Send :size="16" /> Publier
                    </button>
                </div>
            </header>
            <div
                v-if="Object.keys(errors).length"
                class="border-b border-red-200 bg-red-50 px-5 py-2 text-sm text-red-700"
            >
                {{ Object.values(errors).flat().join(" · ") }}
            </div>
            <div
                v-if="connectingFrom"
                class="border-b border-blue-200 bg-blue-50 px-5 py-2 text-center text-sm font-medium text-blue-700"
            >
                Mode connexion actif : cliquez sur la poignée gauche du bloc de
                destination.
                <button class="ml-2 underline" @click="connectingFrom = null">
                    Annuler
                </button>
            </div>
            <div
                class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[230px_minmax(0,1fr)]"
                :style="{
                    minWidth: `${canvasWidth + 230}px`,
                }"
            >
                <aside
                    class="z-10 overflow-y-auto border-r border-slate-200 bg-white p-4"
                >
                    <p
                        class="text-xs font-bold uppercase tracking-[.14em] text-slate-400"
                    >
                        Bibliothèque
                    </p>
                    <h2 class="mt-1 font-bold text-slate-900">
                        Étapes du processus
                    </h2>
                    <div class="mt-4 space-y-2">
                        <button
                            v-for="item in palette"
                            :key="item.type"
                            draggable="true"
                            :disabled="!editable"
                            @dragstart="paletteDrag($event, item)"
                            @click="addNode(item)"
                            class="group flex w-full items-center gap-3 rounded-xl border border-slate-200 p-3 text-left transition hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span
                                class="grid h-9 w-9 place-items-center rounded-lg"
                                :class="nodeStyles[item.type]"
                                ><component :is="item.icon" :size="17" /></span
                            ><span
                                ><b class="block text-sm text-slate-800">{{
                                    item.label
                                }}</b
                                ><small class="text-slate-400">{{
                                    item.hint
                                }}</small></span
                            ><Plus
                                :size="15"
                                class="ml-auto text-slate-300 group-hover:text-blue-600"
                            />
                        </button>
                    </div>
                    <button
                        v-if="editable"
                        @click="applyTemplate"
                        class="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-blue-50 px-3 py-2.5 text-sm font-semibold text-blue-700 hover:bg-blue-100"
                    >
                        <GitBranch :size="17" /> Modèle standard
                    </button>
                    <div
                        class="mt-5 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500"
                    >
                        <b class="text-slate-700">Astuce</b><br />Glissez les
                        blocs, puis cliquez sur la poignée sous le départ et la
                        poignée au-dessus de l'arrivée.
                    </div>
                </aside>
                <div class="relative min-h-0 min-w-0">
                    <main
                        ref="canvas"
                        class="workflow-canvas relative h-full min-h-0 min-w-0 w-full cursor-grab overflow-x-hidden overflow-y-scroll overscroll-contain bg-[#f8fafc] active:cursor-grabbing"
                        @mousedown="startPan"
                        @dragover.prevent
                        @drop.prevent="dropNode"
                    >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none"
                        :style="{
                            width: `${canvasWidth}px`,
                            height: `${canvasHeight}px`,
                        }"
                    ></div>
                    <div
                        data-canvas-background
                        class="absolute inset-0 opacity-70"
                        style="
                            background-image: radial-gradient(
                                #cbd5e1 1.2px,
                                transparent 1.2px
                            );
                            background-size: 22px 22px;
                        "
                    ></div>
                    <div
                        class="absolute left-0 top-0 origin-top-left"
                        :style="{
                            width: `${canvasWidth}px`,
                            height: `${canvasHeight}px`,
                            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
                        }"
                    >
                        <svg
                            class="absolute inset-0 h-full w-full overflow-visible"
                        >
                            <defs>
                                <marker
                                    id="workflow-arrow"
                                    markerWidth="10"
                                    markerHeight="10"
                                    refX="8"
                                    refY="3"
                                    orient="auto"
                                >
                                    <path d="M0,0 L0,6 L9,3 z" fill="#64748b" />
                                </marker>
                            </defs>
                            <g
                                v-for="edge in edges"
                                :key="edge.id"
                                class="cursor-pointer"
                                @click.stop="
                                    selectedEdgeId = edge.id;
                                    selectedNodeId = null;
                                "
                            >
                                <path
                                    :d="pathFor(edge)"
                                    fill="none"
                                    stroke="transparent"
                                    stroke-width="18"
                                />
                                <path
                                    :d="pathFor(edge)"
                                    fill="none"
                                    :stroke="
                                        selectedEdgeId === edge.id
                                            ? '#2563eb'
                                            : '#64748b'
                                    "
                                    :stroke-width="
                                        selectedEdgeId === edge.id ? 3 : 2
                                    "
                                    marker-end="url(#workflow-arrow)"
                                />
                                <g
                                    v-if="edge.label"
                                    :transform="`translate(${edgeLabelPosition(edge).x}, ${edgeLabelPosition(edge).y})`"
                                >
                                    <rect
                                        x="-31"
                                        y="-13"
                                        width="62"
                                        height="26"
                                        rx="13"
                                        fill="white"
                                        stroke="#cbd5e1"
                                    />
                                    <text
                                        text-anchor="middle"
                                        dominant-baseline="middle"
                                        font-size="12"
                                        font-weight="600"
                                        fill="#475569"
                                    >
                                        {{ edge.label }}
                                    </text>
                                </g>
                            </g>
                        </svg>
                        <div
                            v-for="node in nodes"
                            :key="node.id"
                            class="workflow-node absolute select-none overflow-visible rounded-xl border-2 shadow-sm transition-shadow"
                            :class="[
                                selectedNodeId === node.id
                                    ? 'shadow-lg ring-4 ring-blue-100'
                                    : 'hover:shadow-md',
                                connectingFrom === node.id
                                    ? 'ring-4 ring-cyan-200'
                                    : '',
                            ]"
                            :style="{
                                left: `${node.position.x}px`,
                                top: `${node.position.y}px`,
                                width: `${nodeSize(node).width}px`,
                                height: `${nodeSize(node).height}px`,
                                backgroundColor: nodeTheme(node).background,
                                borderColor:
                                    selectedNodeId === node.id
                                        ? '#3B82F6'
                                        : nodeTheme(node).border,
                            }"
                            @mousedown="startNodeDrag($event, node)"
                            @click.stop="
                                selectedNodeId = node.id;
                                selectedEdgeId = null;
                            "
                        >
                            <button
                                v-if="node.type !== 'start'"
                                class="absolute -top-3 left-1/2 h-6 w-6 -translate-x-1/2 rounded-full border-2 border-white bg-slate-400 shadow transition hover:scale-125 hover:bg-blue-600"
                                title="Destination"
                                @mousedown.stop
                                @click.stop="connect(node)"
                            ></button>
                            <div
                                class="flex h-full min-h-0 flex-col overflow-hidden rounded-[9px] bg-white"
                            >
                                <div
                                    class="flex shrink-0 items-center gap-2 border-b px-3 py-2.5"
                                    :style="{
                                        backgroundColor: nodeTheme(node).icon,
                                        borderColor: nodeTheme(node).border,
                                    }"
                                >
                                    <span
                                        class="grid h-8 min-w-8 place-items-center rounded-md bg-white/90 text-xs font-extrabold shadow-sm"
                                        :style="{
                                            color: nodeTheme(node).accent,
                                        }"
                                        >{{ stepNumber(node) }}</span
                                    >
                                    <component
                                        :is="
                                            palette.find(
                                                (item) =>
                                                    item.type === node.type,
                                            )?.icon || Square
                                        "
                                        :size="17"
                                        :style="{
                                            color: nodeTheme(node).accent,
                                        }"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="text-[10px] font-bold uppercase tracking-wider"
                                            :style="{
                                                color: nodeTheme(node).accent,
                                            }"
                                        >
                                            {{
                                                palette.find(
                                                    (item) =>
                                                        item.type === node.type,
                                                )?.label
                                            }}
                                        </div>
                                        <input
                                            v-if="
                                                inlineEdit?.nodeId ===
                                                    node.id &&
                                                inlineEdit.field === 'label'
                                            "
                                            v-model="inlineValue"
                                            data-inline-field="label"
                                            class="w-full rounded border border-blue-300 px-1 text-sm font-bold"
                                            @mousedown.stop
                                            @click.stop
                                            @blur="commitInlineEdit"
                                            @keydown.enter.prevent="
                                                commitInlineEdit
                                            "
                                            @keydown.esc.prevent="
                                                cancelInlineEdit
                                            "
                                        />
                                        <div
                                            v-else
                                            class="break-words text-sm font-bold leading-5 text-slate-800"
                                            @dblclick="
                                                beginInlineEdit(
                                                    $event,
                                                    node,
                                                    'label',
                                                )
                                            "
                                        >
                                            {{ node.label }}
                                        </div>
                                    </div>
                                </div>
                                <div
                                    class="min-h-0 flex-1 overflow-auto px-3 py-3"
                                >
                                    <textarea
                                        v-if="
                                            inlineEdit?.nodeId === node.id &&
                                            inlineEdit.field === 'description'
                                        "
                                        v-model="inlineValue"
                                        data-inline-field="description"
                                        rows="3"
                                        class="w-full rounded border border-blue-300 p-1 text-xs"
                                        @mousedown.stop
                                        @click.stop
                                        @blur="commitInlineEdit"
                                        @keydown.ctrl.enter.prevent="
                                            commitInlineEdit
                                        "
                                        @keydown.esc.prevent="cancelInlineEdit"
                                    ></textarea>
                                    <p
                                        v-else
                                        class="whitespace-pre-wrap break-words text-xs leading-5 text-slate-600"
                                        @dblclick="
                                            beginInlineEdit(
                                                $event,
                                                node,
                                                'description',
                                            )
                                        "
                                    >
                                        {{
                                            node.data.description ||
                                            "Double-cliquez pour ajouter une description…"
                                        }}
                                    </p>
                                    <div
                                        v-if="node.data.owner"
                                        class="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500"
                                    >
                                        Responsable : {{ node.data.owner }}
                                    </div>
                                </div>
                            </div>
                            <template v-if="editable">
                                <div
                                    class="absolute -right-1 top-8 bottom-8 z-20 w-2 cursor-ew-resize"
                                    title="Modifier la largeur"
                                    @mousedown.stop.prevent="
                                        startResize($event, node, 'x')
                                    "
                                ></div>
                                <div
                                    class="absolute -bottom-1 left-8 right-8 z-20 h-2 cursor-ns-resize"
                                    title="Modifier la hauteur"
                                    @mousedown.stop.prevent="
                                        startResize($event, node, 'y')
                                    "
                                ></div>
                                <div
                                    class="absolute -bottom-1 -right-1 z-30 h-4 w-4 cursor-nwse-resize rounded-tl border-l-2 border-t-2 border-blue-400 bg-white"
                                    title="Redimensionner"
                                    @mousedown.stop.prevent="
                                        startResize($event, node, 'xy')
                                    "
                                ></div>
                            </template>
                            <button
                                v-if="node.type !== 'end'"
                                class="absolute -bottom-3 left-1/2 h-6 w-6 -translate-x-1/2 rounded-full border-2 border-white bg-blue-600 shadow transition hover:scale-125 hover:bg-cyan-500"
                                title="Créer une connexion"
                                @mousedown.stop
                                @click.stop="connect(node)"
                            ></button>
                        </div>
                    </div>
                    <div
                        v-if="editable && (selectedNodeId || selectedEdgeId)"
                        class="absolute right-4 top-4 max-w-[390px] rounded-xl border border-slate-200 bg-white p-2 shadow-lg"
                    >
                        <div class="flex items-center gap-2">
                            <Palette
                                v-if="selectedNodeId"
                                :size="16"
                                class="text-violet-600"
                            /><span
                                class="px-1 text-xs font-semibold text-slate-600"
                                >{{
                                    selectedNodeId
                                        ? "Couleur du bloc"
                                        : "Liaison sélectionnée"
                                }}</span
                            ><button
                                class="ml-auto rounded-lg p-2 text-red-600 hover:bg-red-50"
                                title="Supprimer"
                                @click="removeSelected"
                            >
                                <Trash2 :size="17" />
                            </button>
                        </div>
                        <div
                            v-if="selectedNodeId"
                            class="mt-1 grid grid-cols-10 gap-1.5 border-t border-slate-100 pt-2"
                        >
                            <button
                                v-for="color in colorThemes"
                                :key="color.key"
                                type="button"
                                class="h-6 w-6 rounded-full border-2 transition hover:scale-110"
                                :class="
                                    selectedNode?.data.color === color.key
                                        ? 'ring-2 ring-blue-500 ring-offset-1'
                                        : ''
                                "
                                :style="{
                                    backgroundColor: color.icon,
                                    borderColor: color.border,
                                }"
                                :title="color.name"
                                @click="setNodeColor(color)"
                            ></button>
                        </div>
                    </div>
                    <div
                        class="absolute bottom-5 left-1/2 z-30 flex -translate-x-1/2 items-center gap-1 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg"
                    >
                        <button
                            class="toolbar-btn border-0"
                            @click="zoom = Math.max(0.35, zoom - 0.1)"
                        >
                            <ZoomOut :size="17" /></button
                        ><span
                            class="w-14 text-center text-xs font-semibold text-slate-600"
                            >{{ Math.round(zoom * 100) }}%</span
                        ><button
                            class="toolbar-btn border-0"
                            @click="zoom = Math.min(1.8, zoom + 0.1)"
                        >
                            <ZoomIn :size="17" /></button
                        ><button
                            class="toolbar-btn ml-1 border-0"
                            @click="fitView"
                        >
                            <Maximize2 :size="17" />
                        </button>
                    </div>
                    </main>
                </div>
            </div>
        </div>
        <WorkflowAIModal
            :show="showAIModal"
            :endpoint="
                route('workflows.ai.generate', [project.id, workflow.id])
            "
            :configured="aiConfigured"
            :current-graph="currentGraphForAI"
            @close="showAIModal = false"
            @apply="applyAIProposal"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.workflow-page-scroll {
    scrollbar-color: rgb(100 116 139) rgb(226 232 240);
    scrollbar-width: auto;
}
.workflow-page-scroll::-webkit-scrollbar {
    height: 14px;
}
.workflow-page-scroll::-webkit-scrollbar-track {
    background: rgb(226 232 240);
}
.workflow-page-scroll::-webkit-scrollbar-thumb {
    min-width: 80px;
    border: 3px solid rgb(226 232 240);
    border-radius: 9999px;
    background: rgb(100 116 139);
}
.workflow-page-scroll::-webkit-scrollbar-thumb:hover {
    background: rgb(71 85 105);
}
.workflow-canvas {
    scrollbar-gutter: stable;
    scrollbar-color: rgb(148 163 184) rgb(241 245 249);
    scrollbar-width: thin;
}
.workflow-canvas::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}
.workflow-canvas::-webkit-scrollbar-track {
    background: rgb(241 245 249);
}
.workflow-canvas::-webkit-scrollbar-thumb {
    border: 2px solid rgb(241 245 249);
    border-radius: 9999px;
    background: rgb(148 163 184);
}
.workflow-canvas::-webkit-scrollbar-thumb:hover {
    background: rgb(100 116 139);
}
.toolbar-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid rgb(226 232 240);
    border-radius: 0.5rem;
    padding: 0.5rem 0.65rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: rgb(71 85 105);
    background: white;
}
.toolbar-btn:hover:not(:disabled) {
    background: rgb(248 250 252);
}
.toolbar-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}
.field-label {
    display: block;
    margin-top: 1rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: rgb(71 85 105);
}
.field-input {
    margin-top: 0.35rem;
    width: 100%;
    border: 1px solid rgb(203 213 225);
    border-radius: 0.6rem;
    padding: 0.55rem 0.7rem;
    font-size: 0.85rem;
    color: rgb(30 41 59);
}
.field-input:focus {
    border-color: rgb(59 130 246);
    outline: none;
    box-shadow: 0 0 0 3px rgb(219 234 254);
}
.field-input:disabled {
    background: rgb(248 250 252);
}
</style>

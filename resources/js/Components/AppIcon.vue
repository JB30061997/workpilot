<script setup>
defineProps({ name: { type: String, required: true }, strokeWidth: { type: Number, default: 1.8 } });
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="strokeWidth" aria-hidden="true">
        <template v-if="name === 'dashboard'"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><path stroke-linecap="round" d="M14 17.5h7M17.5 14v7"/></template>
        <template v-else-if="name === 'plans'"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5h11M9 12h11M9 19h11M4 5.1l.7.7L6.2 4M4 12.1l.7.7 1.5-1.8M4 19.1l.7.7 1.5-1.8"/></template>
        <template v-else-if="name === 'projects'"><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 8h6l2-2h3l2 2h4v10.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2V8Z"/><path stroke-linecap="round" d="M3.5 11.5h17"/></template>
        <template v-else-if="name === 'tasks'"><rect x="3" y="3" width="18" height="18" rx="5"/><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 12 3 3 6-7"/></template>
        <template v-else-if="name === 'sessions'"><rect x="3" y="5" width="18" height="16" rx="3"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18M8 14h3M8 17h7"/></template>
        <template v-else-if="name === 'calendar'"><rect x="3" y="5" width="18" height="16" rx="3"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18"/><path d="M7 14h3v3H7zM14 14h3v3h-3z"/></template>
        <template v-else-if="name === 'ticket'"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a3 3 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a3 3 0 0 0 0-6V7Z"/><path stroke-linecap="round" d="M12 8v2M12 14v2"/></template>
        <template v-else-if="name === 'users'"><circle cx="9" cy="8" r="3.5"/><path stroke-linecap="round" d="M3 20c0-3.4 2.7-6 6-6s6 2.6 6 6M16 5.3a3.5 3.5 0 0 1 0 5.4M17 14.2c2.4.7 4 2.8 4 5.8"/></template>
        <template v-else-if="name === 'referential'"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="18" r="2" fill="currentColor" stroke="none"/></template>
        <template v-else-if="name === 'bell'"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 1 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M14.5 20a3 3 0 0 1-5 0"/></template>
        <template v-else-if="name === 'chevron-down'"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></template>
        <template v-else-if="name === 'chevron-right'"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></template>
        <template v-else-if="name === 'menu'"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></template>
        <template v-else-if="name === 'close'"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18"/></template>
        <template v-else-if="name === 'check'"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></template>
        <template v-else-if="name === 'eye'"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.75"/></template>
        <template v-else-if="name === 'copy'"><rect x="8" y="8" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></template>
        <template v-else-if="name === 'edit'"><path stroke-linecap="round" stroke-linejoin="round" d="m14.5 5.5 4 4M4 20l1.2-4.8L15.6 4.8a2.1 2.1 0 0 1 3 3L8.2 18.2 4 20Z"/></template>
        <template v-else-if="name === 'trash'"><path stroke-linecap="round" d="M4 7h16M9 7V4h6v3M10 11v5M14 11v5"/><path stroke-linecap="round" stroke-linejoin="round" d="m7 7 1 13h8l1-13"/></template>
        <template v-else-if="name === 'activity'"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l2.5-6 4 12 2.5-6h5"/></template>
        <template v-else-if="name === 'mail'"><rect x="3" y="5" width="18" height="14" rx="3"/><path stroke-linecap="round" stroke-linejoin="round" d="m5 8 7 5 7-5"/></template>
        <template v-else-if="name === 'link'"><path stroke-linecap="round" stroke-linejoin="round" d="m10 14 4-4M8.5 16.5l-1 1a3.5 3.5 0 1 1-5-5l3-3a3.5 3.5 0 0 1 5 0M15.5 7.5l1-1a3.5 3.5 0 1 1 5 5l-3 3a3.5 3.5 0 0 1-5 0"/></template>
        <template v-else-if="name === 'refresh'"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5M6.1 8.5A7 7 0 0 1 18.7 7M17.9 15.5A7 7 0 0 1 5.3 17"/></template>
        <template v-else-if="name === 'search'"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m16 16 5 5"/></template>
        <template v-else-if="name === 'building'"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M2 21h20M8 7h5M8 11h5M8 15h2M17 9h3a1 1 0 0 1 1 1v11"/></template>
        <template v-else-if="name === 'department'"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v5M6 21v-4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4M5 8h14v5H5zM3 21h18"/></template>
        <template v-else-if="name === 'panel-left'"><rect x="3" y="3" width="18" height="18" rx="3"/><path stroke-linecap="round" d="M9 3v18M14 9l-3 3 3 3"/></template>
        <template v-else><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5M12 8h.01"/></template>
    </svg>
</template>

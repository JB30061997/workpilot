<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const canvas = ref(null);
let animationFrame = 0;
let resizeObserver;
let motionPreference;
let pageVisible = true;

const drawSmoothPath = (context, points) => {
    context.beginPath();
    context.moveTo(points[0].x, points[0].y);

    for (let index = 1; index < points.length - 1; index++) {
        const midpointX = (points[index].x + points[index + 1].x) / 2;
        const midpointY = (points[index].y + points[index + 1].y) / 2;
        context.quadraticCurveTo(points[index].x, points[index].y, midpointX, midpointY);
    }

    const lastPoint = points[points.length - 1];
    context.lineTo(lastPoint.x, lastPoint.y);
};

const draw = (context, width, height, time) => {
    context.clearRect(0, 0, width, height);
    context.fillStyle = '#f8fafc';
    context.fillRect(0, 0, width, height);

    const isMobile = width < 640;
    const cellSize = isMobile ? 36 : 42;
    const overscanX = Math.max(width * 0.48, 320);
    const overscanY = Math.max(height * 0.18, 150);
    const columns = Math.ceil((width + overscanX * 2) / cellSize);
    const rows = Math.ceil((height + overscanY * 2) / cellSize);
    const centerX = width / 2;
    const verticalSamples = Math.max(rows * 3, 48);
    const horizontalSamples = Math.max(columns * 3, 54);

    const project = (baseX, baseY) => {
        const depth = (baseY + overscanY) / (height + overscanY * 2);
        const perspective = 0.72 + depth * 0.46;
        const normalizedX = (baseX - centerX) / Math.max(width, 1);
        const wave = Math.sin(normalizedX * 15 + time * 0.78 + depth * 5.2) * 15
            + Math.cos(depth * 18 - time * 0.62 + normalizedX * 4.5) * 12
            + Math.sin(normalizedX * 9 - depth * 13 + time * 0.46) * 9
            + Math.cos((normalizedX + depth) * 22 + time * 0.28) * 4;
        const lateralWave = (
            Math.sin(depth * 12 + time * 0.5) * 7
            + Math.cos(normalizedX * 12 - depth * 7 + time * 0.34) * 4
        ) * (0.4 + depth * 0.7);

        return {
            x: centerX + (baseX - centerX) * perspective + lateralWave,
            y: baseY + wave * (0.78 + depth * 0.38),
        };
    };

    context.lineCap = 'round';
    context.lineJoin = 'round';

    for (let column = 0; column <= columns; column++) {
        const baseX = -overscanX + column * ((width + overscanX * 2) / columns);
        const points = [];

        for (let sample = 0; sample <= verticalSamples; sample++) {
            const baseY = -overscanY + sample * ((height + overscanY * 2) / verticalSamples);
            points.push(project(baseX, baseY));
        }

        drawSmoothPath(context, points);
        context.strokeStyle = 'rgba(147, 197, 253, 0.42)';
        context.lineWidth = column % 5 === 0 ? 1.1 : 0.72;
        context.stroke();
    }

    for (let row = 0; row <= rows; row++) {
        const baseY = -overscanY + row * ((height + overscanY * 2) / rows);
        const points = [];

        for (let sample = 0; sample <= horizontalSamples; sample++) {
            const baseX = -overscanX + sample * ((width + overscanX * 2) / horizontalSamples);
            points.push(project(baseX, baseY));
        }

        drawSmoothPath(context, points);
        context.strokeStyle = 'rgba(191, 219, 254, 0.52)';
        context.lineWidth = row % 5 === 0 ? 1.05 : 0.7;
        context.stroke();
    }

    const lightX = width * (0.5 + Math.sin(time * 0.28) * 0.38);
    const lightY = height * (0.48 + Math.cos(time * 0.23) * 0.3);
    const glow = context.createRadialGradient(lightX, lightY, 0, lightX, lightY, Math.max(width, height) * 0.42);
    glow.addColorStop(0, 'rgba(37, 99, 235, 0.16)');
    glow.addColorStop(0.34, 'rgba(96, 165, 250, 0.08)');
    glow.addColorStop(1, 'rgba(96, 165, 250, 0)');
    context.fillStyle = glow;
    context.fillRect(0, 0, width, height);
};

const render = (timestamp = 0) => {
    const element = canvas.value;
    if (!element) return;

    const bounds = element.getBoundingClientRect();
    const pixelRatio = Math.min(window.devicePixelRatio || 1, bounds.width < 640 ? 1.2 : 1.65);
    const targetWidth = Math.max(1, Math.round(bounds.width * pixelRatio));
    const targetHeight = Math.max(1, Math.round(bounds.height * pixelRatio));

    if (element.width !== targetWidth || element.height !== targetHeight) {
        element.width = targetWidth;
        element.height = targetHeight;
    }

    const context = element.getContext('2d');
    context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
    draw(context, bounds.width, bounds.height, timestamp / 1000);

    if (!motionPreference.matches && pageVisible) {
        animationFrame = requestAnimationFrame(render);
    }
};

const restart = () => {
    cancelAnimationFrame(animationFrame);
    render(performance.now());
};

const handleVisibilityChange = () => {
    pageVisible = !document.hidden;
    if (pageVisible && !motionPreference.matches) restart();
};

onMounted(() => {
    motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
    resizeObserver = new ResizeObserver(restart);
    resizeObserver.observe(canvas.value);
    motionPreference.addEventListener('change', restart);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    restart();
});

onUnmounted(() => {
    cancelAnimationFrame(animationFrame);
    resizeObserver?.disconnect();
    motionPreference?.removeEventListener('change', restart);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
    <canvas ref="canvas" class="animated-wave-grid" aria-hidden="true"></canvas>
</template>

<style scoped>
.animated-wave-grid {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    pointer-events: none;
}
</style>

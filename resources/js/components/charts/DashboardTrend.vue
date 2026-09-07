<script setup lang="ts">
import { dashboardDay, dashboardMoney } from '@/lib/dashboard';
import type { DashboardDay } from '@/types/dashboard';
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    type ChartOptions,
} from 'chart.js';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Line } from 'vue-chartjs';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Tooltip,
    Legend,
    Filler,
);
const props = defineProps<{
    data: DashboardDay[];
    mode: 'activity' | 'revenue';
    currency: string;
}>();
const dark = ref(false);
let observer: MutationObserver | undefined;
onMounted(() => {
    const update = () => {
        dark.value = document.documentElement.classList.contains('dark');
    };
    update();
    observer = new MutationObserver(update);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});
onUnmounted(() => observer?.disconnect());

const chartData = computed(() => ({
    labels: props.data.map((day) => dashboardDay(day.date)),
    datasets:
        props.mode === 'revenue'
            ? [
                  {
                      label: `Completed revenue (${props.currency})`,
                      data: props.data.map(
                          (day) => day.revenue[props.currency] ?? 0,
                      ),
                      borderColor: '#10b981',
                      backgroundColor: 'rgba(16,185,129,0.09)',
                      fill: true,
                      tension: 0.25,
                      pointRadius: props.data.length === 1 ? 5 : 2,
                      pointHoverRadius: 5,
                      borderWidth: 2.5,
                  },
              ]
            : [
                  {
                      label: 'Orders',
                      data: props.data.map((day) => day.orders),
                      borderColor: '#6366f1',
                      backgroundColor: 'rgba(99,102,241,0.08)',
                      fill: true,
                      tension: 0.25,
                      pointRadius: props.data.length === 1 ? 5 : 2,
                      pointHoverRadius: 5,
                      borderWidth: 2.5,
                  },
                  {
                      label: 'Submissions',
                      data: props.data.map((day) => day.submissions),
                      borderColor: '#14b8a6',
                      backgroundColor: 'transparent',
                      fill: false,
                      tension: 0.25,
                      pointRadius: props.data.length === 1 ? 5 : 2,
                      pointHoverRadius: 5,
                      borderWidth: 2,
                      borderDash: [5, 4],
                  },
              ],
}));
const options = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: dark.value ? '#18181b' : '#ffffff',
            titleColor: dark.value ? '#fafafa' : '#18181b',
            bodyColor: dark.value ? '#d4d4d8' : '#52525b',
            borderColor: dark.value ? '#3f3f46' : '#e4e4e7',
            borderWidth: 1,
            padding: 12,
            callbacks: {
                label: (context) =>
                    props.mode === 'revenue'
                        ? `${props.currency}: ${dashboardMoney(context.parsed.y ?? 0, props.currency)}`
                        : `${context.dataset.label}: ${(context.parsed.y ?? 0).toLocaleString()}`,
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            border: { display: false },
            ticks: {
                color: dark.value ? '#a1a1aa' : '#71717a',
                autoSkip: true,
                maxTicksLimit: 7,
                maxRotation: 0,
                font: { size: 11 },
            },
        },
        y: {
            beginAtZero: true,
            border: { display: false },
            grid: { color: dark.value ? '#ffffff0a' : '#00000008' },
            ticks: {
                color: dark.value ? '#a1a1aa' : '#71717a',
                precision: props.mode === 'activity' ? 0 : undefined,
                maxTicksLimit: 5,
                padding: 8,
                callback: (value) =>
                    props.mode === 'revenue'
                        ? dashboardMoney(Number(value), props.currency)
                        : Number(value).toLocaleString(),
            },
        },
    },
}));
</script>

<template>
    <div class="h-[260px] w-full min-w-0 sm:h-[290px]">
        <Line
            :data="chartData"
            :options="options"
            role="img"
            :aria-label="
                mode === 'activity'
                    ? 'Daily orders and form submissions. Exact values are available below.'
                    : `Daily completed revenue in ${currency}. Exact values are available below.`
            "
        />
    </div>
</template>

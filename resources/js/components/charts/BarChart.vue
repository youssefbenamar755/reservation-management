<script setup lang="ts">
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend,
  type ChartOptions,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  Title,
  Tooltip,
  Legend
)

interface Props {
  data: Array<{ [key: string]: any; count?: number }>
  labelKey: string
  valueKey?: string
  label?: string
  color?: string
}

const props = withDefaults(defineProps<Props>(), {
  valueKey: 'count',
  label: 'Count',
  color: 'rgb(59, 130, 246)',
})

const chartData = computed(() => {
  const labels = props.data.map((item) => String(item[props.labelKey]))
  const values = props.data.map((item) => Number(item[props.valueKey] || 0))

  return {
    labels,
    datasets: [
      {
        label: props.label,
        data: values,
        backgroundColor: props.color,
        borderColor: props.color,
        borderWidth: 1,
      },
    ],
  }
})

const chartOptions = computed<ChartOptions<'bar'>>(() => {
  const isDark =
    typeof document !== 'undefined' &&
    document.documentElement.classList.contains('dark')
  const textColor = isDark ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.6)'
  const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)'

  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
      legend: {
        display: false,
      },
      tooltip: {
        backgroundColor: isDark ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.95)',
        titleColor: textColor,
        bodyColor: textColor,
        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
        borderWidth: 1,
      },
    },
    scales: {
      x: {
        grid: {
          display: false,
        },
        ticks: {
          color: textColor,
          maxRotation: 45,
          minRotation: 0,
          font: {
            size: 11,
          },
        },
      },
      y: {
        beginAtZero: true,
        grid: {
          color: gridColor,
        },
        ticks: {
          color: textColor,
          precision: 0,
        },
      },
    },
  }
})
</script>

<template>
  <div class="h-[250px] sm:h-[300px] w-full">
    <Bar :data="chartData" :options="chartOptions" />
  </div>
</template>

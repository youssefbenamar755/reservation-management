<script setup lang="ts">
// @ts-nocheck
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  type ChartOptions,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend
)

interface Props {
  data: Array<{ date: string; revenue: number }>
  currency?: string
}

const props = withDefaults(defineProps<Props>(), {
  currency: 'USD',
})

const chartData = computed(() => {
  const labels = props.data.map((item) => {
    const date = new Date(item.date)
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  })

  return {
    labels,
    datasets: [
      {
        label: 'Revenue',
        data: props.data.map((item) => item.revenue),
        borderColor: 'rgb(34, 197, 94)',
        backgroundColor: 'rgba(34, 197, 94, 0.1)',
        tension: 0.4,
        fill: true,
      },
    ],
  }
})

const chartOptions = computed<ChartOptions<'line'>>(() => {
  const isDark = document.documentElement.classList.contains('dark')
  const textColor = isDark ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.6)'
  const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)'

  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        display: false,
      },
      tooltip: {
        mode: 'index',
        intersect: false,
        backgroundColor: isDark ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.95)',
        titleColor: textColor,
        bodyColor: textColor,
        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
        borderWidth: 1,
        callbacks: {
          label: (context) => {
            const value = context.parsed.y
            // @ts-ignore
            return new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: props.currency,
            }).format(value)
          },
        },
      },
    },
    scales: {
      x: {
        grid: {
          display: false,
        },
        ticks: {
          maxRotation: 45,
          minRotation: 45,
          color: textColor,
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
          callback: (value) => {
            // @ts-ignore
            return new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: props.currency,
              notation: 'compact',
              maximumFractionDigits: 0,
            }).format(Number(value))
          },
        },
      },
    },
  }
})
</script>

<template>
  <div class="h-[250px] sm:h-[300px] w-full">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>


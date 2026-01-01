<script setup lang="ts">
import { Pie } from 'vue-chartjs'
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
  type ChartOptions,
} from 'chart.js'
import { computed } from 'vue'

ChartJS.register(ArcElement, Tooltip, Legend)

interface Props {
  data: Array<{ [key: string]: any; revenue?: number; count?: number }>
  labelKey: string
  valueKey?: string
}

const props = withDefaults(defineProps<Props>(), {
  valueKey: 'revenue',
})

// Generate colors for pie chart
const generateColors = (count: number) => {
  const colors = [
    'rgb(59, 130, 246)',   // blue
    'rgb(34, 197, 94)',     // green
    'rgb(239, 68, 68)',   // red
    'rgb(168, 85, 247)',  // purple
    'rgb(245, 158, 11)',  // amber
    'rgb(236, 72, 153)',  // pink
    'rgb(14, 165, 233)',  // sky
    'rgb(251, 146, 60)',  // orange
    'rgb(34, 211, 238)',  // cyan
    'rgb(139, 92, 246)',  // violet
  ]
  
  const result: string[] = []
  for (let i = 0; i < count; i++) {
    result.push(colors[i % colors.length])
  }
  return result
}

const chartData = computed(() => {
  const labels = props.data.map((item) => String(item[props.labelKey]))
  const values = props.data.map((item) => Number(item[props.valueKey] || 0))
  const colors = generateColors(props.data.length)

  return {
    labels,
    datasets: [
      {
        data: values,
        backgroundColor: colors,
        borderColor: colors.map(c => c.replace('rgb', 'rgba').replace(')', ', 0.8)')),
        borderWidth: 2,
      },
    ],
  }
})

const chartOptions = computed<ChartOptions<'pie'>>(() => {
  const isDark = document.documentElement.classList.contains('dark')
  const textColor = isDark ? 'rgba(255, 255, 255, 0.7)' : 'rgba(0, 0, 0, 0.6)'

  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'right',
        labels: {
          color: textColor,
          padding: 15,
          font: {
            size: 12,
          },
        },
      },
      tooltip: {
        backgroundColor: isDark ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.95)',
        titleColor: textColor,
        bodyColor: textColor,
        borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
        borderWidth: 1,
        callbacks: {
          label: (context) => {
            const label = context.label || ''
            const value = context.parsed || 0
            const total = context.dataset.data.reduce((a: number, b: number) => a + b, 0)
            const percentage = ((value / total) * 100).toFixed(1)
            return `${label}: ${new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
            }).format(value)} (${percentage}%)`
          },
        },
      },
    },
  }
})
</script>

<template>
  <div class="h-[300px]">
    <Pie :data="chartData" :options="chartOptions" />
  </div>
</template>


<script setup lang="ts">
import type { ChartPoint, RangeKey } from './chartData'
import UPlot from 'uplot'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'
import { useSession } from '../../stores/session'
import { alignedData, RANGES, summarize, timeTickFormatter, wholeNumberFormatter } from './chartData'
import 'uplot/dist/uPlot.min.css'

const props = defineProps<{
  points: ChartPoint[]
  loading: boolean
}>()

const range = defineModel<RangeKey>('range', { required: true })

const { t, locale } = useI18n()
const theme = useTheme()
const session = useSession()

const host = ref<HTMLElement | null>(null)
let chart: UPlot | null = null
let observer: ResizeObserver | null = null

const rangeLabel = computed(() => t(`monitorDetail.ranges.${range.value}`))
const summary = computed(() => {
  const s = summarize(props.points)
  if (s.count === 0)
    return t('monitorDetail.chart.empty', { range: rangeLabel.value })
  return t('monitorDetail.chart.summary', {
    range: rangeLabel.value,
    count: s.count,
    min: s.min ?? 0,
    avg: s.avg ?? 0,
    max: s.max ?? 0,
  })
})

function colors() {
  const c = theme.current.value.colors
  return {
    line: c.primary ?? '#2346B5',
    fill: theme.current.value.dark ? 'rgba(143,167,244,0.10)' : 'rgba(35,70,181,0.08)',
    text: theme.current.value.dark ? '#9AA6B6' : '#526070',
    grid: theme.current.value.dark ? '#2A3544' : '#D3DAE2',
  }
}

function options(): UPlot.Options {
  const palette = colors()
  const zone = session.current?.timezone ?? 'UTC'
  const timeTicks = timeTickFormatter(locale.value, zone)
  const ms = wholeNumberFormatter(locale.value)
  const axis = {
    stroke: palette.text,
    font: '12px Barlow, system-ui, sans-serif',
    grid: { stroke: palette.grid, width: 1 },
    ticks: { stroke: palette.grid, width: 1 },
  }
  return {
    width: host.value?.clientWidth || 600,
    height: 220,
    legend: { show: false },
    cursor: {
      drag: { x: false, y: false },
    },
    tzDate: ts => UPlot.tzDate(new Date(ts * 1000), zone),
    scales: {
      x: { time: true },
      y: {
        range: (_u, _min, max) => [
          0,
          Math.max(10, max * 1.1),
        ],
      },
    },
    axes: [
      {
        ...axis,
        // uPlot hands over the spacing it chose for the ticks, which sets how precise each label is.
        values: (_u, ticks, _axis, _space, spacing) => timeTicks(ticks, spacing),
      },
      {
        ...axis,
        values: (_u, ticks) => ticks.map(v => `${ms.format(v)} ms`),
        size: 70,
      },
    ],
    series: [
      {},
      {
        label: t('monitorDetail.chart.latency'),
        stroke: palette.line,
        fill: palette.fill,
        width: 1.75,
        spanGaps: false,
        points: { show: false },
      },
    ],
  }
}

function build(): void {
  if (!host.value)
    return
  chart?.destroy()
  chart = new UPlot(options(), alignedData(props.points), host.value)
}

onMounted(() => {
  build()
  if (typeof ResizeObserver === 'function' && host.value) {
    observer = new ResizeObserver(() => {
      const width = host.value?.clientWidth
      if (chart && width)
        chart.setSize({ width, height: 260 })
    })
    observer.observe(host.value)
  }
})

onBeforeUnmount(() => {
  observer?.disconnect()
  chart?.destroy()
  chart = null
})

watch(() => props.points, points => chart?.setData(alignedData(points)))
// Colours and labels are baked into the chart options, so a theme or language change rebuilds it.
watch(() => [theme.current.value.dark, locale.value, session.current?.timezone], build)
</script>

<template>
  <section :aria-label="t('monitorDetail.chart.title')">
    <div class="d-flex flex-wrap align-center justify-space-between mb-1">
      <h2 class="text-subtitle-1">
        {{ t('monitorDetail.chart.title') }}
      </h2>
      <v-tabs v-model="range" density="compact" class="range-tabs" :aria-label="t('monitorDetail.chart.range')">
        <v-tab v-for="r in RANGES" :key="r.key" :value="r.key" :data-test="`range-${r.key}`">
          {{ t(`monitorDetail.ranges.${r.key}`) }}
        </v-tab>
      </v-tabs>
    </div>
    <v-progress-linear v-if="loading" indeterminate :aria-label="t('monitorDetail.chart.loading')" />
    <div ref="host" role="img" :aria-label="summary" class="latency-chart" />
    <p class="text-body-2 mt-2" data-test="chart-summary" aria-live="polite">
      {{ summary }}
    </p>
  </section>
</template>

<style scoped>
.range-tabs :deep(.v-tab) {
  min-width: 0;
  padding: 0 12px;
}

.latency-chart {
  min-height: 220px;
  width: 100%;
}
</style>

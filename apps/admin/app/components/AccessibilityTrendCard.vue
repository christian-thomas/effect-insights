<script setup lang="ts">
import type { A11yImpact } from '~/types/accessibility';

interface TrendPoint {
  scanId: string;
  finishedAt: string | null;
  total: number;
  byImpact: Record<A11yImpact, number>;
}

interface TrendResponse {
  data: {
    domainId: string;
    days: number;
    points: TrendPoint[];
  };
}

const props = defineProps<{
  domainId: string;
}>();

const days = ref<7 | 30 | 90 | 365>(90);
const loading = ref(false);
const error = ref<Error | null>(null);
const points = ref<TrendPoint[]>([]);

const load = async (): Promise<void> => {
  loading.value = true;
  error.value = null;
  try {
    const response = await $api<TrendResponse>(
      `/api/domains/${props.domainId}/accessibility-trend?days=${days.value}`,
    );
    points.value = response.data.points;
  } catch (caught) {
    error.value = caught instanceof Error ? caught : new Error(String(caught));
  } finally {
    loading.value = false;
  }
};

await load();
watch(days, load);

// Self-contained SVG stacked-bar — no chart-lib dep. Each bar shows one
// scan; segments stack Critical → Serious → Moderate → Minor.
const impactColors: Record<A11yImpact, string> = {
  critical: '#dc2626', // red-600
  serious: '#f59e0b', // amber-500
  moderate: '#3b82f6', // blue-500
  minor: '#9ca3af', // gray-400
};

const maxTotal = computed((): number => {
  if (points.value.length === 0) return 0;
  return Math.max(...points.value.map((point) => point.total));
});

const chartWidth = 600;
const chartHeight = 160;
const barGap = 4;
const barWidth = computed((): number => {
  if (points.value.length === 0) return 0;
  return Math.max(2, chartWidth / points.value.length - barGap);
});

const yScale = (value: number): number => {
  if (maxTotal.value === 0) return 0;
  return (value / maxTotal.value) * chartHeight;
};

const segmentsForPoint = (
  point: TrendPoint,
  index: number,
): Array<{
  x: number;
  y: number;
  height: number;
  color: string;
  impact: A11yImpact;
  count: number;
}> => {
  const x = index * (barWidth.value + barGap);
  let yCursor = chartHeight;
  const segments: ReturnType<typeof segmentsForPoint> = [];
  for (const impact of ['critical', 'serious', 'moderate', 'minor'] as A11yImpact[]) {
    const count = point.byImpact[impact];
    if (count === 0) continue;
    const height = yScale(count);
    yCursor -= height;
    segments.push({ x, y: yCursor, height, color: impactColors[impact], impact, count });
  }
  return segments;
};

const formatTip = (point: TrendPoint): string => {
  const date = point.finishedAt ? new Date(point.finishedAt).toLocaleDateString() : 'unknown';
  const counts = (['critical', 'serious', 'moderate', 'minor'] as A11yImpact[])
    .filter((impact) => point.byImpact[impact] > 0)
    .map((impact) => `${impact}: ${point.byImpact[impact]}`)
    .join(' · ');
  return `${date} — ${point.total} violation${point.total === 1 ? '' : 's'}${counts ? ` (${counts})` : ''}`;
};
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h3 class="font-semibold">Accessibility trend</h3>
          <p class="text-xs text-gray-500">
            Violations per completed scan over time. Stacked by impact level.
          </p>
        </div>
        <UButtonGroup size="xs">
          <UButton
            v-for="window in [7, 30, 90, 365] as const"
            :key="window"
            :variant="days === window ? 'solid' : 'soft'"
            color="neutral"
            @click="days = window"
          >
            {{ window === 365 ? '1y' : `${window}d` }}
          </UButton>
        </UButtonGroup>
      </div>
    </template>

    <UAlert
      v-if="error"
      class="mb-3"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <USkeleton v-if="loading && points.length === 0" class="h-40 w-full" />

    <p v-else-if="points.length === 0" class="text-sm text-gray-500 py-6 text-center">
      No completed accessibility scans in the last {{ days }} days. Trigger one from the
      Accessibility tab to populate this chart.
    </p>

    <template v-else>
      <svg
        :viewBox="`0 0 ${chartWidth} ${chartHeight + 20}`"
        class="w-full h-auto"
        role="img"
        aria-label="Accessibility violations over time"
      >
        <!-- Bars -->
        <g v-for="(point, index) in points" :key="point.scanId">
          <rect
            v-for="segment in segmentsForPoint(point, index)"
            :key="`${point.scanId}-${segment.impact}`"
            :x="segment.x"
            :y="segment.y"
            :width="barWidth"
            :height="segment.height"
            :fill="segment.color"
          >
            <title>{{ formatTip(point) }}</title>
          </rect>
        </g>
      </svg>

      <div class="flex items-center gap-4 mt-2 text-xs flex-wrap">
        <span
          v-for="impact in ['critical', 'serious', 'moderate', 'minor'] as A11yImpact[]"
          :key="impact"
          class="flex items-center gap-1.5"
        >
          <span
            class="inline-block w-3 h-3 rounded-sm"
            :style="{ backgroundColor: impactColors[impact] }"
          />
          <span class="capitalize">{{ impact }}</span>
        </span>
        <span class="text-gray-400 ml-auto"
          >{{ points.length }} scan{{ points.length === 1 ? '' : 's' }}</span
        >
      </div>
    </template>
  </UCard>
</template>

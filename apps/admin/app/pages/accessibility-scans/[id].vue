<script setup lang="ts">
import type { BadgeProps } from '@nuxt/ui';
import type { A11yImpact, AccessibilityFinding } from '~/types/accessibility';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useAccessibilityScansStore();
const { selected: scan, findings, loading } = storeToRefs(scansStore);

const scanId = computed((): string => String(route.params.id));

await scansStore.show(scanId.value);

// Group findings by impact for the rendered sections. Backend already
// orders Critical → Serious → Moderate → Minor → unknown, so iterating
// in insertion order keeps the section ordering correct.
const findingsByImpact = computed((): Record<string, AccessibilityFinding[]> => {
  const groups: Record<string, AccessibilityFinding[]> = {};
  for (const finding of findings.value) {
    const key = finding.impact;
    if (!groups[key]) groups[key] = [];
    groups[key].push(finding);
  }
  return groups;
});

const impactColor: Record<A11yImpact, BadgeColor> = {
  critical: 'error',
  serious: 'warning',
  moderate: 'info',
  minor: 'neutral',
};

const impactLabel: Record<A11yImpact, string> = {
  critical: 'Critical',
  serious: 'Serious',
  moderate: 'Moderate',
  minor: 'Minor',
};

const statusColor: Record<string, BadgeColor> = {
  queued: 'neutral',
  running: 'info',
  completed: 'success',
  failed: 'error',
};

const expandedRule = ref<string | null>(null);

const toggleRule = (ruleId: string): void => {
  expandedRule.value = expandedRule.value === ruleId ? null : ruleId;
};
</script>

<template>
  <UContainer class="py-6">
    <div v-if="scan">
      <UPageHeader
        :title="`Accessibility scan ${scan.id.slice(0, 8)}…`"
        :description="`Status: ${scan.status} · ${scan.urlsScanned} URLs scanned · ${scan.violationsTotal} violations`"
      >
        <template #links>
          <UBadge :color="statusColor[scan.status] ?? 'neutral'" variant="soft">
            {{ scan.status }}
          </UBadge>
        </template>
      </UPageHeader>

      <UAlert
        v-if="scan.errorLog"
        color="error"
        variant="soft"
        :title="`Scan failed: ${String(scan.errorLog.message ?? 'unknown error')}`"
        class="mt-4"
      />

      <UCard v-if="scan.violationsByImpact" class="mt-6">
        <template #header>
          <h3 class="font-semibold">Violations by impact</h3>
        </template>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div
            v-for="impact in (['critical', 'serious', 'moderate', 'minor'] as A11yImpact[])"
            :key="impact"
            class="rounded border border-gray-200 dark:border-gray-800 p-3"
          >
            <p class="text-xs text-gray-500">{{ impactLabel[impact] }}</p>
            <div class="flex items-baseline gap-2 mt-1">
              <span class="text-2xl font-semibold tabular-nums">
                {{ scan.violationsByImpact[impact] }}
              </span>
              <UBadge :color="impactColor[impact]" variant="subtle" size="sm">
                {{ impactLabel[impact] }}
              </UBadge>
            </div>
          </div>
        </div>
      </UCard>

      <div v-if="findings.length === 0 && !loading" class="mt-6">
        <UAlert
          color="success"
          variant="soft"
          icon="i-lucide-shield-check"
          title="No violations found"
          description="The scan completed without any axe-core violations. Nice work."
        />
      </div>

      <div v-for="(group, impact) in findingsByImpact" :key="impact" class="mt-6">
        <div class="flex items-center gap-2 mb-2">
          <UBadge :color="impactColor[impact as A11yImpact] ?? 'neutral'" variant="soft">
            {{ impactLabel[impact as A11yImpact] ?? impact }}
          </UBadge>
          <span class="text-sm text-gray-500">{{ group.length }} violation{{ group.length === 1 ? '' : 's' }}</span>
        </div>

        <div class="space-y-2">
          <UCard
            v-for="finding in group"
            :key="finding.id"
            class="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-gray-900"
            @click="toggleRule(finding.id)"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <code class="text-sm font-medium">{{ finding.ruleId }}</code>
                  <span class="text-xs text-gray-500">
                    {{ finding.nodes.length }} element{{ finding.nodes.length === 1 ? '' : 's' }}
                  </span>
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">
                  {{ finding.description }}
                </p>
                <p class="text-xs text-gray-500 mt-1 break-all">
                  on <code>{{ finding.url }}</code>
                </p>
              </div>
              <UIcon
                :name="expandedRule === finding.id ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                class="text-gray-400 mt-1 flex-shrink-0"
              />
            </div>

            <div v-if="expandedRule === finding.id" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-800 space-y-3">
              <div>
                <p class="text-xs font-medium text-gray-500 mb-1">Help</p>
                <p class="text-sm">{{ finding.helpText }}</p>
                <a
                  :href="finding.helpUrl"
                  target="_blank"
                  rel="noopener"
                  class="text-xs text-primary-600 hover:underline"
                  @click.stop
                >
                  axe documentation →
                </a>
              </div>

              <div v-if="finding.tags.length > 0">
                <p class="text-xs font-medium text-gray-500 mb-1">Tags</p>
                <div class="flex flex-wrap gap-1">
                  <UBadge v-for="tag in finding.tags" :key="tag" variant="subtle" size="sm">
                    {{ tag }}
                  </UBadge>
                </div>
              </div>

              <div v-if="finding.nodes.length > 0">
                <p class="text-xs font-medium text-gray-500 mb-1">Failing elements</p>
                <ul class="space-y-2">
                  <li
                    v-for="(node, index) in finding.nodes"
                    :key="index"
                    class="rounded bg-gray-50 dark:bg-gray-900 p-2 text-xs"
                  >
                    <p v-if="node.target" class="font-mono mb-1">
                      {{ node.target.join(' ') }}
                    </p>
                    <pre v-if="node.html" class="whitespace-pre-wrap break-all text-gray-700 dark:text-gray-300"><code>{{ node.html }}</code></pre>
                    <p v-if="node.failureSummary" class="text-gray-500 mt-1">
                      {{ node.failureSummary }}
                    </p>
                  </li>
                </ul>
              </div>
            </div>
          </UCard>
        </div>
      </div>
    </div>
  </UContainer>
</template>

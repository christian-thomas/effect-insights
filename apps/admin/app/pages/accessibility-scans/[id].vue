<script setup lang="ts">
import type { BadgeProps } from '@nuxt/ui';
import type { A11yImpact, AccessibilityFinding } from '~/types/accessibility';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useAccessibilityScansStore();
const { selected: scan, findings, loading } = storeToRefs(scansStore);

const scanId = computed((): string => String(route.params.id));

await scansStore.show(scanId.value);

type FindingFilter = 'all' | 'wcag' | 'best-practice';

const filter = ref<FindingFilter>('all');

// axe-core tags every violation. WCAG rules carry tags like wcag2a /
// wcag2aa / wcag21aa / wcag143; best-practice rules carry the literal
// "best-practice" tag. A single rule can be both — when in doubt we
// treat WCAG as the stronger classification.
const isWcag = (finding: AccessibilityFinding): boolean =>
  finding.tags.some((tag) => tag.startsWith('wcag'));

const isBestPractice = (finding: AccessibilityFinding): boolean =>
  finding.tags.includes('best-practice') && !isWcag(finding);

const filteredFindings = computed((): AccessibilityFinding[] => {
  switch (filter.value) {
    case 'wcag':
      return findings.value.filter(isWcag);
    case 'best-practice':
      return findings.value.filter(isBestPractice);
    default:
      return [...findings.value];
  }
});

const wcagCount = computed((): number => findings.value.filter(isWcag).length);
const bestPracticeCount = computed((): number => findings.value.filter(isBestPractice).length);

// Group filtered findings by impact for the rendered sections. Backend
// already orders Critical → Serious → Moderate → Minor → unknown, so
// iterating in insertion order keeps the section ordering correct.
const findingsByImpact = computed((): Record<string, AccessibilityFinding[]> => {
  const groups: Record<string, AccessibilityFinding[]> = {};
  for (const finding of filteredFindings.value) {
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
  partial: 'warning',
  failed: 'error',
};

const expandedRule = ref<string | null>(null);

const toggleRule = (ruleId: string): void => {
  expandedRule.value = expandedRule.value === ruleId ? null : ruleId;
};

const overridesStore = useAccessibilityRuleOverridesStore();
const toast = useToast();
const muting = ref<string | null>(null);

type MuteScope = 'domain' | 'org';

const muteOpen = ref(false);
const muteFinding = ref<AccessibilityFinding | null>(null);
const muteScope = ref<MuteScope>('domain');
const muteReason = ref('');

const openMute = (finding: AccessibilityFinding, scope: MuteScope): void => {
  if (scope === 'domain' && !scan.value?.domainId) return;
  muteFinding.value = finding;
  muteScope.value = scope;
  muteReason.value = '';
  muteOpen.value = true;
};

const muteRuleForDomain = (finding: AccessibilityFinding): void => {
  openMute(finding, 'domain');
};

const muteRuleOrgWide = (finding: AccessibilityFinding): void => {
  openMute(finding, 'org');
};

const muteTitle = computed((): string => {
  const finding = muteFinding.value;
  if (finding === null) return 'Mute rule?';
  if (muteScope.value === 'org') return `Mute “${finding.ruleId}” org-wide?`;
  return `Mute “${finding.ruleId}” for this domain?`;
});

const muteDescription = computed((): string => {
  if (muteScope.value === 'org') {
    return 'Future scans (and this view) will treat this rule as muted across every domain in this organisation.';
  }
  return 'Future scans of this domain will treat this rule as muted. Other domains in the organisation are unaffected.';
});

const muteHelp = 'Optional but recommended — e.g. tracked in Linear ENG-…';

const confirmMute = async (close: () => void): Promise<void> => {
  const finding = muteFinding.value;
  if (finding === null) {
    close();
    return;
  }
  const isOrgWide = muteScope.value === 'org';
  const domainId = isOrgWide ? null : (scan.value?.domainId ?? null);
  if (!isOrgWide && domainId === null) {
    close();
    return;
  }
  const reason = muteReason.value.trim();
  muting.value = finding.id;
  try {
    await overridesStore.create({
      rule_id: finding.ruleId,
      domain_id: domainId,
      reason: reason === '' ? null : reason,
    });
    await scansStore.show(scanId.value);
    toast.add({
      title: isOrgWide ? `Muted ${finding.ruleId}` : `Muted ${finding.ruleId} on this domain`,
      description: isOrgWide
        ? 'Future scans (and this view) will treat this rule as muted across every domain.'
        : undefined,
      color: 'success',
      icon: 'i-lucide-volume-x',
    });
    close();
  } catch (caught) {
    toast.add({
      title: 'Failed to mute rule',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    muting.value = null;
  }
};

const isInFlight = computed((): boolean => scan.value?.status === 'running' || scan.value?.status === 'queued');

const progressPercent = computed((): number => {
  if (!scan.value || !scan.value.urlsDiscovered) return 0;
  return Math.min(100, Math.round((scan.value.urlsScanned / scan.value.urlsDiscovered) * 100));
});

// Poll the scan endpoint while the batch is still running so
// urls_scanned + findings populate live. `silent: true` keeps the
// findings list from flashing its loading state on every tick.
const POLL_INTERVAL_MS = 2000;
let pollHandle: ReturnType<typeof setInterval> | null = null;

const startPolling = (): void => {
  if (pollHandle !== null) return;
  pollHandle = setInterval((): void => {
    if (!isInFlight.value) {
      stopPolling();
      return;
    }
    void scansStore.show(scanId.value, { silent: true });
  }, POLL_INTERVAL_MS);
};

const stopPolling = (): void => {
  if (pollHandle !== null) {
    clearInterval(pollHandle);
    pollHandle = null;
  }
};

onMounted((): void => {
  if (isInFlight.value) startPolling();
});

onBeforeUnmount(stopPolling);

watch(isInFlight, (running: boolean): void => {
  if (running) startPolling();
  else stopPolling();
});

const retrying = ref(false);

const retryFailed = async (): Promise<void> => {
  if (!scan.value || retrying.value) return;
  retrying.value = true;
  try {
    await scansStore.retryFailed(scan.value.id);
    toast.add({
      title: 'Retrying failed URLs',
      description: 'The scan will resume — progress should pick back up shortly.',
      color: 'success',
      icon: 'i-lucide-refresh-cw',
    });
  } catch (error: unknown) {
    toast.add({
      title: 'Could not retry',
      description: error instanceof Error ? error.message : 'Unknown error',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    retrying.value = false;
  }
};
</script>

<template>
  <PageHeader
    :title="scan ? `Accessibility scan ${scan.id.slice(0, 8)}…` : 'Accessibility scan'"
    :description="
      scan
        ? `Status: ${scan.status} · ${scan.urlsScanned}/${scan.urlsDiscovered} URLs scanned · ${scan.violationsTotal} violations`
        : undefined
    "
  >
    <template v-if="scan" #right>
      <UBadge :color="statusColor[scan.status] ?? 'neutral'" variant="soft">
        {{ scan.status }}
      </UBadge>
    </template>
  </PageHeader>

  <div class="px-4 sm:px-6 lg:px-8 pt-4 pb-6 space-y-4">
    <div v-if="scan">
      <UCard v-if="isInFlight" class="mt-4">
        <div class="flex items-center gap-4">
          <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-primary-500" />
          <div class="flex-1">
            <div class="flex items-center justify-between text-sm mb-1">
              <span class="font-medium">
                {{ scan.status === 'queued' ? 'Waiting for worker…' : 'Running axe-core…' }}
              </span>
              <span class="text-gray-500">
                {{ scan.urlsScanned }} / {{ scan.urlsDiscovered }} ({{ progressPercent }}%)
              </span>
            </div>
            <UProgress :model-value="progressPercent" :max="100" />
          </div>
        </div>
      </UCard>

      <UAlert
        v-if="scan.status === 'partial' && scan.failedUrls.length > 0"
        color="warning"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="`Partial scan — ${scan.failedUrls.length} URL${scan.failedUrls.length === 1 ? '' : 's'} didn't make it`"
        description="The chunk job hit infra failures it couldn't ride out. Retry just the failed URLs against this scan — successful findings are kept."
        class="mt-4"
      >
        <template #actions>
          <UButton
            icon="i-lucide-refresh-cw"
            :loading="retrying"
            :label="`Retry failed URLs (${scan.failedUrls.length})`"
            @click="retryFailed"
          />
        </template>
      </UAlert>

      <UAlert
        v-else-if="scan.errorLog"
        color="error"
        variant="soft"
        :title="`Scan failed: ${String(scan.errorLog.message ?? 'unknown error')}`"
        :description="scan.failedUrls.length > 0 ? `${scan.failedUrls.length} URL${scan.failedUrls.length === 1 ? '' : 's'} can be retried below.` : undefined"
        class="mt-4"
      >
        <template v-if="scan.failedUrls.length > 0" #actions>
          <UButton
            icon="i-lucide-refresh-cw"
            :loading="retrying"
            :label="`Retry failed URLs (${scan.failedUrls.length})`"
            @click="retryFailed"
          />
        </template>
      </UAlert>

      <UCard v-if="scan.violationsByImpact" class="mt-6">
        <template #header>
          <h3 class="font-semibold">Violations by impact</h3>
        </template>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div
            v-for="impact in ['critical', 'serious', 'moderate', 'minor'] as A11yImpact[]"
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

      <div v-if="findings.length > 0" class="mt-6 flex items-center gap-2 flex-wrap">
        <span class="text-sm text-gray-500 mr-1">Show:</span>
        <UButton
          :variant="filter === 'all' ? 'solid' : 'soft'"
          color="neutral"
          size="xs"
          @click="filter = 'all'"
        >
          All ({{ findings.length }})
        </UButton>
        <UButton
          :variant="filter === 'wcag' ? 'solid' : 'soft'"
          color="neutral"
          size="xs"
          @click="filter = 'wcag'"
        >
          WCAG ({{ wcagCount }})
        </UButton>
        <UButton
          :variant="filter === 'best-practice' ? 'solid' : 'soft'"
          color="neutral"
          size="xs"
          @click="filter = 'best-practice'"
        >
          Best practice ({{ bestPracticeCount }})
        </UButton>
        <span class="text-xs text-gray-400 ml-2">
          WCAG = legally-required conformance rules (alert-worthy). Best practice = advisory.
        </span>
      </div>

      <div v-if="filteredFindings.length === 0 && findings.length > 0" class="mt-6">
        <UAlert
          color="info"
          variant="soft"
          icon="i-lucide-filter"
          title="No findings under this filter"
          description="Switch back to All or pick a different tag scope to see violations from this scan."
        />
      </div>

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
          <span class="text-sm text-gray-500"
            >{{ group.length }} violation{{ group.length === 1 ? '' : 's' }}</span
          >
        </div>

        <div class="space-y-2">
          <UCard
            v-for="finding in group"
            :key="finding.id"
            class="cursor-pointer transition-colors hover:bg-gray-50 dark:hover:bg-gray-900"
            :class="finding.muted ? 'opacity-60' : ''"
            @click="toggleRule(finding.id)"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <code class="text-sm font-medium">{{ finding.ruleId }}</code>
                  <UBadge
                    v-if="finding.muted"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    icon="i-lucide-volume-x"
                    title="Muted by an active rule override — doesn't contribute to alert thresholds."
                  >
                    Muted
                  </UBadge>
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
                :name="
                  expandedRule === finding.id ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'
                "
                class="text-gray-400 mt-1 flex-shrink-0"
              />
            </div>

            <div
              v-if="expandedRule === finding.id"
              class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-800 space-y-3"
            >
              <div>
                <p class="text-xs font-medium text-gray-500 mb-1">Screenshot</p>
                <!--
                  The screenshot endpoint is cross-origin (api vs admin)
                  and tenant-scoped via Sanctum's session cookie, so the
                  img tag has to opt in to credentials with
                  `crossorigin="use-credentials"`. Without it the cookie
                  doesn't travel and the API treats the request as
                  unauthenticated.
                -->
                <img
                  v-if="finding.screenshotUrl"
                  :src="finding.screenshotUrl"
                  :alt="`Element triggering ${finding.ruleId}`"
                  loading="lazy"
                  crossorigin="use-credentials"
                  class="max-w-md max-h-96 rounded-md border border-gray-200 dark:border-gray-800"
                />
                <p v-else class="text-xs text-gray-400 italic">
                  Screenshot unavailable — the scanner couldn't render this element.
                </p>
              </div>

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
                    <pre
                      v-if="node.html"
                      class="whitespace-pre-wrap break-all text-gray-700 dark:text-gray-300"
                    ><code>{{ node.html }}</code></pre>
                    <p v-if="node.failureSummary" class="text-gray-500 mt-1">
                      {{ node.failureSummary }}
                    </p>
                  </li>
                </ul>
              </div>

              <div
                v-if="!finding.muted"
                class="pt-3 border-t border-gray-200 dark:border-gray-800 flex items-center gap-2 flex-wrap"
              >
                <span class="text-xs text-gray-500">Mute this rule:</span>
                <UButton
                  size="xs"
                  variant="soft"
                  color="neutral"
                  icon="i-lucide-volume-x"
                  :loading="muting === finding.id"
                  @click.stop="muteRuleForDomain(finding)"
                >
                  Just this domain
                </UButton>
                <UButton
                  size="xs"
                  variant="soft"
                  color="neutral"
                  icon="i-lucide-volume-x"
                  :loading="muting === finding.id"
                  @click.stop="muteRuleOrgWide(finding)"
                >
                  Org-wide
                </UButton>
                <span class="text-xs text-gray-400 ml-2">
                  Stops this rule from counting toward alert thresholds. Manage overrides in
                  <NuxtLink to="/accessibility-rule-overrides" class="underline">
                    settings </NuxtLink
                  >.
                </span>
              </div>
            </div>
          </UCard>
        </div>
      </div>
    </div>
  </div>

  <ConfirmModal
    v-model:open="muteOpen"
    :title="muteTitle"
    :description="muteDescription"
    confirm-label="Mute rule"
    confirm-color="primary"
    confirm-icon="i-lucide-volume-x"
    :loading="muting !== null"
    @confirm="confirmMute"
  >
    <template #body>
      <UFormField label="Reason" name="reason" :help="muteHelp">
        <UTextarea
          v-model="muteReason"
          :rows="3"
          autofocus
          placeholder="e.g. Tracked in Linear ENG-1234, accepted by accessibility lead."
          class="w-full"
        />
      </UFormField>
    </template>
  </ConfirmModal>
</template>

<script setup lang="ts">
import type { Domain, ScanCadence } from '@effectinsight/types/domain';

const route = useRoute();
const toast = useToast();
const domainsStore = useDomainsStore();
const domainId = route.params.id as string;

const domain = ref<Domain | null>(null);
const loading = ref(true);
const savingCadence = ref(false);
const savingA11yCadence = ref(false);
const error = ref<Error | null>(null);

try {
  domain.value = await domainsStore.fetchOne(domainId);
} catch (caught) {
  error.value = caught instanceof Error ? caught : new Error(String(caught));
} finally {
  loading.value = false;
}

const cadenceOptions: Array<{ label: string; value: ScanCadence }> = [
  { label: 'Weekly', value: 'weekly' },
  { label: 'Monthly', value: 'monthly' },
  { label: 'Never (manual only)', value: 'never' },
];

const formatLastScanned = (iso: string | null): string => {
  if (!iso) return 'Never scanned';
  return new Date(iso).toLocaleString();
};

const onCadenceChange = async (next: ScanCadence): Promise<void> => {
  if (!domain.value) return;
  const previous = domain.value.scanCadence;
  if (previous === next) return;
  savingCadence.value = true;
  try {
    const updated = await domainsStore.update(domainId, { scan_cadence: next });
    domain.value = updated;
    toast.add({
      title: `Scan cadence set to ${next}`,
      color: 'success',
      icon: 'i-lucide-check',
    });
  } catch (caught) {
    toast.add({
      title: 'Failed to update cadence',
      description: caught instanceof Error ? caught.message : String(caught),
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    savingCadence.value = false;
  }
};

const onA11yCadenceChange = async (next: ScanCadence): Promise<void> => {
  if (!domain.value) return;
  if (domain.value.a11yScanCadence === next) return;
  savingA11yCadence.value = true;
  try {
    const updated = await domainsStore.update(domainId, { a11y_scan_cadence: next });
    domain.value = updated;
    toast.add({
      title: `Accessibility cadence set to ${next}`,
      color: 'success',
      icon: 'i-lucide-check',
    });
  } catch (caught) {
    toast.add({
      title: 'Failed to update accessibility cadence',
      description: caught instanceof Error ? caught.message : String(caught),
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    savingA11yCadence.value = false;
  }
};

const embedSnippet = computed(() => {
  if (!domain.value) return '';
  // The escape on the closing tag is required: Vue SFC parsers close the
  // <script setup> block on a literal closing-script-tag sequence even when
  // it appears inside a TypeScript template literal.
  // eslint-disable-next-line no-useless-escape
  return `<script src="https://assets.effectinsight.com/loader.js" data-domain-id="${domain.value.publicKey}" async><\/script>`;
});

const copyEmbed = async (): Promise<void> => {
  if (!domain.value) return;
  await navigator.clipboard.writeText(embedSnippet.value);
  toast.add({ title: 'Embed snippet copied', color: 'success', icon: 'i-lucide-check' });
};
</script>

<template>
  <UDashboardNavbar :title="domain?.fqdn ?? 'Domain'">
    <template #leading>
      <UButton color="neutral" variant="ghost" icon="i-lucide-arrow-left" to="/domains" />
    </template>
    <template #right>
      <UButton
        v-if="domain"
        :to="`/domains/${domain.id}/scans`"
        color="neutral"
        variant="soft"
        icon="i-lucide-radar"
        label="Scans" />
      <UButton
        v-if="domain"
        :to="`/domains/${domain.id}/accessibility-scans`"
        color="neutral"
        variant="soft"
        icon="i-lucide-accessibility"
        label="A11y" />
      <UButton
        v-if="domain"
        :to="`/domains/${domain.id}/banner`"
        icon="i-lucide-palette"
        label="Banner config"
    /></template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-6">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <template v-if="domain">
      <UCard>
        <template #header>
          <h3 class="font-semibold">Overview</h3>
        </template>
        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <dt class="text-gray-500">FQDN</dt>
            <dd class="font-mono">{{ domain.fqdn }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Apex</dt>
            <dd class="font-mono">{{ domain.apexDomain }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Status</dt>
            <dd>
              <UBadge
                :color="
                  domain.status === 'active'
                    ? 'success'
                    : domain.status === 'paused'
                      ? 'warning'
                      : 'neutral'
                "
                variant="subtle"
              >
                {{ domain.status }}
              </UBadge>
            </dd>
          </div>
          <div class="sm:col-span-3">
            <dt class="text-gray-500">Public key</dt>
            <dd class="font-mono break-all">{{ domain.publicKey }}</dd>
          </div>
        </dl>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Scheduled scans</h3>
        </template>

        <div class="space-y-6">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
            <div>
              <label class="text-gray-500 text-sm block mb-1">Cookie scan cadence</label>
              <USelectMenu
                :model-value="domain.scanCadence"
                :items="cadenceOptions"
                value-key="value"
                :loading="savingCadence"
                @update:model-value="onCadenceChange"
              />
              <p class="text-xs text-gray-500 mt-1">
                Cookie/script discovery via the scanner worker. Runs at 04:00 UTC; scans stagger
                across the cadence window so the worker isn't hammered.
              </p>
            </div>
            <div>
              <label class="text-gray-500 text-sm block mb-1">Last cookie scan</label>
              <div class="text-sm font-mono" :title="domain.lastScannedAt ?? ''">
                {{ formatLastScanned(domain.lastScannedAt) }}
              </div>
            </div>
          </div>

          <div
            class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end pt-4 border-t border-gray-200 dark:border-gray-800"
          >
            <div>
              <label class="text-gray-500 text-sm block mb-1">Accessibility scan cadence</label>
              <USelectMenu
                :model-value="domain.a11yScanCadence"
                :items="cadenceOptions"
                value-key="value"
                :loading="savingA11yCadence"
                @update:model-value="onA11yCadenceChange"
              />
              <p class="text-xs text-gray-500 mt-1">
                axe-core WCAG audit. Runs at 05:00 UTC (1h offset from the cookie scanner so the two
                don't fight for browser-rendering quota). Default
                <code>never</code> — opt-in per domain.
              </p>
            </div>
            <div>
              <label class="text-gray-500 text-sm block mb-1">Last accessibility scan</label>
              <div class="text-sm font-mono" :title="domain.lastA11yScannedAt ?? ''">
                {{ formatLastScanned(domain.lastA11yScannedAt) }}
              </div>
            </div>
          </div>
        </div>
      </UCard>

      <UCard>
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">Embed snippet</h3>
            <UButton size="xs" icon="i-lucide-copy" label="Copy" @click="copyEmbed" />
          </div>
        </template>
        <p class="mb-2 text-sm text-gray-500">
          Paste this once near the top of <code>&lt;head&gt;</code> on every page of
          {{ domain.fqdn }}. The loader fetches the current banner config and renders the banner
          pre-consent.
        </p>
        <pre
          class="rounded bg-gray-50 dark:bg-gray-900 p-4 text-xs overflow-x-auto"
        ><code>{{ embedSnippet }}</code></pre>
      </UCard>

      <CookiePolicyPanel
        :domain-id="domain.id"
        :public-key="domain.publicKey"
        :fqdn="domain.fqdn"
      />

      <AccessibilityTrendCard :domain-id="domain.id" />
    </template>

    <USkeleton v-else-if="loading" class="h-48 w-full" />
  </div>
</template>

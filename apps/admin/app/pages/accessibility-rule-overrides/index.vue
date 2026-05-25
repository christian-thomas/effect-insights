<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import type { AccessibilityRuleOverride } from '~/types/accessibilityRuleOverride';

const overridesStore = useAccessibilityRuleOverridesStore();
const domainsStore = useDomainsStore();
const { items: overrides, loading, error } = storeToRefs(overridesStore);
const toast = useToast();

await Promise.all([overridesStore.list(), domainsStore.list()]);

const columns: TableColumn<AccessibilityRuleOverride>[] = [
  { accessorKey: 'ruleId', header: 'Rule' },
  { accessorKey: 'domainId', header: 'Scope' },
  { accessorKey: 'reason', header: 'Reason' },
  { accessorKey: 'createdAt', header: 'Created' },
  { id: 'actions', header: '' },
];

const domainFqdn = (id: string | null): string => {
  if (id === null) return 'Org-wide';
  return domainsStore.items.find((domain) => domain.id === id)?.fqdn ?? id.slice(0, 8);
};

const formatTime = (iso: string): string => new Date(iso).toLocaleString();

const unmuteOpen = ref(false);
const unmuteTarget = ref<AccessibilityRuleOverride | null>(null);
const unmuting = ref(false);

const removeOverride = (row: AccessibilityRuleOverride): void => {
  unmuteTarget.value = row;
  unmuteOpen.value = true;
};

const confirmUnmute = async (close: () => void): Promise<void> => {
  const target = unmuteTarget.value;
  if (target === null) {
    close();
    return;
  }
  unmuting.value = true;
  try {
    await overridesStore.remove(target.id);
    toast.add({ title: 'Override removed', color: 'success', icon: 'i-lucide-check' });
    close();
  } catch (caught) {
    toast.add({
      title: 'Could not remove override',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    unmuting.value = false;
  }
};
</script>

<template>
  <UDashboardNavbar title="Accessibility rule overrides">
    <template #leading>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
        to="/accessibility-scans"
      /> </template
  ></UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-4">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <UCard>
      <p class="text-sm text-gray-500">
        Muted rules don't contribute to accessibility alert thresholds. Org-wide overrides apply
        across every domain; domain-scoped overrides apply to a single domain. To mute a rule, open
        an accessibility scan, expand a finding, and use the Mute buttons in the finding card.
      </p>
    </UCard>

    <UCard v-if="!loading && overrides.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-volume-2" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No rule overrides yet</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Every axe-core rule that fires on this org's scans is currently active. Add an override
          from any scan's finding card when you want to silence a rule.
        </p>
      </div>
    </UCard>

    <UTable v-else :data="overrides" :columns="columns" :loading="loading" class="w-full">
      <template #ruleId-cell="{ row }">
        <code class="text-sm">{{ row.original.ruleId }}</code>
      </template>
      <template #domainId-cell="{ row }">
        <UBadge
          v-if="row.original.domainId === null"
          color="neutral"
          variant="subtle"
          icon="i-lucide-globe"
        >
          Org-wide
        </UBadge>
        <span v-else class="text-sm">{{ domainFqdn(row.original.domainId) }}</span>
      </template>
      <template #reason-cell="{ row }">
        <span class="text-sm text-gray-500" :title="row.original.reason ?? ''">
          {{ row.original.reason ?? '—' }}
        </span>
      </template>
      <template #createdAt-cell="{ row }">
        <span class="text-xs text-gray-500">{{ formatTime(row.original.createdAt) }}</span>
      </template>
      <template #actions-cell="{ row }">
        <UButton
          size="xs"
          variant="ghost"
          color="error"
          icon="i-lucide-trash-2"
          @click="removeOverride(row.original)"
        />
      </template>
    </UTable>
  </div>

  <ConfirmModal
    v-model:open="unmuteOpen"
    :title="unmuteTarget ? `Unmute “${unmuteTarget.ruleId}”?` : 'Unmute rule?'"
    description="Future scans will start counting this rule toward alert thresholds again."
    confirm-label="Unmute"
    confirm-icon="i-lucide-trash-2"
    cancel-label="Keep muted"
    :loading="unmuting"
    @confirm="confirmUnmute"
  />
</template>

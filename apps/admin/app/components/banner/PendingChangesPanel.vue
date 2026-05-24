<script setup lang="ts">
import type { BannerConfigDiff } from '~/stores/bannerConfigs';

const props = defineProps<{ diff: BannerConfigDiff | null }>();

const fieldLabels: Record<string, string> = {
  layout: 'Layout',
  locale_default: 'Default locale',
  colors: 'Colours',
  copy: 'Copy / text',
  locales_enabled: 'Locales enabled',
  geo_rules: 'Geo rules',
  'behaviour.honourGpc': 'Honour Global Privacy Control',
  'behaviour.autoDenyOnDismiss': 'Auto-deny on dismiss',
  'behaviour.crossSubdomainConsent': 'Cross-subdomain consent',
  'behaviour.rePromptAfterDays': 'Re-prompt after (days)',
};

const labelFor = (field: string): string => {
  if (fieldLabels[field]) return fieldLabels[field];
  if (field.startsWith('gcm_defaults.')) {
    return `GCM · ${field.slice('gcm_defaults.'.length)}`;
  }
  return field;
};

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'on' : 'off';
  if (typeof value === 'string' || typeof value === 'number') return String(value);
  return JSON.stringify(value);
};

const heading = computed(
  (): { tone: 'info' | 'warning' | 'success'; title: string; body: string } => {
    const diff = props.diff;
    if (!diff || (!diff.hasChanges && !diff.isFirstPublish)) {
      return {
        tone: 'success',
        title: 'No pending changes',
        body: 'The draft matches the published version. Nothing to publish.',
      };
    }
    if (diff.isFirstPublish) {
      return {
        tone: 'info',
        title: 'First publish',
        body: 'This domain has never been published. Publishing will make the banner go live.',
      };
    }
    if (diff.isMaterial) {
      return {
        tone: 'warning',
        title: 'Material changes — visitors will be re-prompted',
        body: 'These changes alter what consent actually controls, so every consented visitor will see the banner again on their next visit.',
      };
    }
    return {
      tone: 'success',
      title: 'Cosmetic changes only',
      body: "No visitor will be re-prompted — these are appearance / copy changes that don't change what tracking runs.",
    };
  },
);
</script>

<template>
  <UCard>
    <template #header>
      <h3 class="font-semibold">Pending changes</h3>
    </template>

    <UAlert
      :color="heading.tone === 'warning' ? 'warning' : heading.tone === 'info' ? 'info' : 'success'"
      :variant="heading.tone === 'warning' ? 'subtle' : 'soft'"
      :icon="
        heading.tone === 'warning'
          ? 'i-lucide-triangle-alert'
          : heading.tone === 'info'
            ? 'i-lucide-info'
            : 'i-lucide-check'
      "
      :title="heading.title"
      :description="heading.body"
    />

    <ul v-if="diff && diff.changes.length > 0" class="mt-3 space-y-2">
      <li
        v-for="change in diff.changes"
        :key="change.field"
        class="flex items-start gap-2 text-sm border border-gray-100 dark:border-gray-800 rounded-md px-3 py-2"
      >
        <UBadge
          :color="change.category === 'material' ? 'warning' : 'neutral'"
          variant="subtle"
          size="sm"
        >
          {{ change.category }}
        </UBadge>
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ labelFor(change.field) }}</p>
          <p class="text-xs text-gray-500 mt-0.5">{{ change.reason }}</p>
          <p class="text-xs font-mono mt-1 break-all">
            <span class="text-gray-500">before:</span> {{ formatValue(change.previous) }}
          </p>
          <p class="text-xs font-mono break-all">
            <span class="text-gray-500">after:</span> {{ formatValue(change.next) }}
          </p>
        </div>
      </li>
    </ul>
  </UCard>
</template>

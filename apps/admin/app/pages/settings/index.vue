<script setup lang="ts">
import type { A11yAlertScope, A11yAlertThreshold } from '~/types/organisation';

const store = useCurrentOrganisationStore();
const auth = useAuthStore();
const { organisation, loading, saving } = storeToRefs(store);
const toast = useToast();

await store.fetch();

interface FormState {
  consentRetentionDays: number | null;
  notificationEmail: string;
  a11yAlertThreshold: A11yAlertThreshold | null;
  a11yAlertScope: A11yAlertScope;
}

const form = reactive<FormState>({
  consentRetentionDays: organisation.value?.consentRetentionDays ?? null,
  notificationEmail: organisation.value?.notificationEmail ?? '',
  a11yAlertThreshold: organisation.value?.a11yAlertThreshold ?? null,
  a11yAlertScope: organisation.value?.a11yAlertScope ?? 'all',
});

const thresholdOptions: Array<{ label: string; value: A11yAlertThreshold | null }> = [
  { label: 'Inherit platform default', value: null },
  { label: 'Critical only', value: 'critical' },
  { label: 'Serious and worse', value: 'serious' },
  { label: 'Moderate and worse', value: 'moderate' },
  { label: 'Every violation', value: 'minor' },
];

const scopeOptions: Array<{ label: string; value: A11yAlertScope }> = [
  { label: 'All findings (WCAG + best-practice)', value: 'all' },
  { label: 'WCAG only (drop best-practice)', value: 'wcag_only' },
];

const onSubmit = async (): Promise<void> => {
  try {
    await store.update({
      consent_retention_days: form.consentRetentionDays,
      notification_email: form.notificationEmail.trim() === '' ? null : form.notificationEmail,
      a11y_alert_threshold: form.a11yAlertThreshold,
      a11y_alert_scope: form.a11yAlertScope,
    });
    toast.add({
      title: 'Settings saved',
      icon: 'i-lucide-check',
      color: 'success',
    });
  } catch (caught) {
    toast.add({
      title: 'Could not save settings',
      description: caught instanceof Error ? caught.message : 'Please try again',
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  }
};
</script>

<template>
  <UDashboardNavbar :title="`Settings · ${auth.currentOrganisation?.name ?? ''}`" />

  <div class="p-4 sm:p-6 lg:p-8 max-w-3xl space-y-6">
    <p class="text-sm text-gray-500">
      Runtime preferences for the active organisation. Changes apply to every member of this org
      and take effect immediately. The org's name + slug are managed by a superuser on the
      Organisations page.
    </p>

    <USkeleton v-if="loading && !organisation" class="h-64 w-full" />

    <UForm v-else :state="form" class="space-y-6" @submit="onSubmit">
      <UCard>
        <template #header>
          <h3 class="font-semibold">Consent retention</h3>
        </template>

        <UFormField
          label="Retain consent events for"
          help="Leave blank to retain forever. Anything older than this is hard-deleted by the nightly 02:00 UTC purge."
        >
          <div class="flex items-center gap-2">
            <UInput
              v-model.number="form.consentRetentionDays"
              type="number"
              min="1"
              max="3650"
              placeholder="Forever"
              class="w-32"
            />
            <span class="text-sm text-gray-500">days</span>
          </div>
        </UFormField>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Notifications</h3>
        </template>

        <UFormField
          label="Notification email recipients"
          help="Comma-separated. Receives unknown-cookie alerts, cookie scan failures, accessibility violations, and accessibility scan failures alongside the Slack channel. Leave blank for Slack-only."
        >
          <UInput
            v-model="form.notificationEmail"
            placeholder="alerts@client.com, ops@client.com"
            type="text"
          />
        </UFormField>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Accessibility alerts</h3>
        </template>

        <div class="space-y-4">
          <UFormField
            label="Alert threshold"
            help="The minimum severity that triggers an alert. 'Inherit platform default' falls back to the env-configured threshold (currently 'serious')."
          >
            <USelectMenu
              v-model="form.a11yAlertThreshold"
              :items="thresholdOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Counted findings"
            help="axe-core flags both WCAG conformance failures and 'best-practice' suggestions. Choose whether best-practice findings count toward the threshold."
          >
            <USelectMenu
              v-model="form.a11yAlertScope"
              :items="scopeOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>
        </div>
      </UCard>

      <div class="flex justify-end">
        <UButton
          type="submit"
          icon="i-lucide-save"
          label="Save changes"
          :loading="saving"
          :disabled="loading"
        />
      </div>
    </UForm>
  </div>
</template>

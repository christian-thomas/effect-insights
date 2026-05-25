<script setup lang="ts">
import type { A11yAlertScope, A11yAlertThreshold } from '~/types/organisation';

const store = useCurrentOrganisationStore();
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
  <UDashboardNavbar title="Settings">
    <template #right>
      <OrgSwitcher />
    </template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 max-w-4xl">
    <USkeleton v-if="loading && !organisation" class="h-96 w-full" />

    <UCard v-else>
      <template #header>
        <div>
          <h2 class="text-base font-semibold">Organisation settings</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            Runtime preferences for the active organisation. Changes apply to every member of
            this org and take effect immediately.
          </p>
        </div>
      </template>

      <UForm
        :state="form"
        class="divide-y divide-gray-200 dark:divide-gray-800"
        @submit="onSubmit"
      >
        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6 first:pt-0">
          <div>
            <h3 class="font-medium">Consent retention</h3>
            <p class="text-sm text-gray-500 mt-1">
              How long consent events are kept before the nightly 02:00 UTC purge hard-deletes
              anything older.
            </p>
          </div>
          <div class="md:col-span-2">
            <UFormField label="Retain consent events for" name="consent_retention_days">
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
              <template #help>Leave blank to retain forever.</template>
            </UFormField>
          </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6">
          <div>
            <h3 class="font-medium">Notifications</h3>
            <p class="text-sm text-gray-500 mt-1">
              Where alerts are sent in addition to the configured Slack channel.
            </p>
          </div>
          <div class="md:col-span-2">
            <UFormField label="Email recipients" name="notification_email">
              <UInput
                v-model="form.notificationEmail"
                placeholder="alerts@client.com, ops@client.com"
                type="text"
              />
              <template #help>
                Comma-separated. Receives unknown-cookie alerts, scan failures, and accessibility
                violations. Leave blank for Slack-only.
              </template>
            </UFormField>
          </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6">
          <div>
            <h3 class="font-medium">Accessibility alerts</h3>
            <p class="text-sm text-gray-500 mt-1">
              Tune which axe-core findings trigger alerts for this organisation.
            </p>
          </div>
          <div class="md:col-span-2 space-y-4">
            <UFormField label="Alert threshold" name="a11y_alert_threshold">
              <USelectMenu
                v-model="form.a11yAlertThreshold"
                :items="thresholdOptions"
                value-key="value"
                class="w-full sm:w-72"
              />
              <template #help>
                The minimum severity that triggers an alert. 'Inherit platform default' falls
                back to the env-configured threshold.
              </template>
            </UFormField>
            <UFormField label="Counted findings" name="a11y_alert_scope">
              <USelectMenu
                v-model="form.a11yAlertScope"
                :items="scopeOptions"
                value-key="value"
                class="w-full sm:w-72"
              />
              <template #help>
                axe-core flags both WCAG conformance failures and 'best-practice' suggestions.
                Choose whether best-practice findings count toward the threshold.
              </template>
            </UFormField>
          </div>
        </section>

        <div class="flex justify-end pt-6">
          <UButton
            type="submit"
            icon="i-lucide-save"
            label="Save changes"
            :loading="saving"
            :disabled="loading"
          />
        </div>
      </UForm>
    </UCard>
  </div>
</template>

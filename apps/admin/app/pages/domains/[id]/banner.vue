<script setup lang="ts">
import {
  bannerDraftSchema,
  type BannerCopy,
  type BannerDraftInput,
} from '@effectinsight/types/schemas/banner';
import type { FormSubmitEvent } from '@nuxt/ui';
import { geoCountries } from '~/components/banner/countries';
import { defaultCopyForLocale } from '~/components/banner/defaults';
import { localeLabel, supportedLocales } from '~/components/banner/locales';

const route = useRoute();
const toast = useToast();
const bannerStore = useBannerConfigsStore();
const domainId = route.params.id as string;

await bannerStore.fetch(domainId);
await bannerStore.fetchHistory(domainId);

const slot = computed(() => bannerStore.forDomain(domainId));
const draft = computed(() => slot.value.draft);
const published = computed(() => slot.value.published);
const diff = computed(() => slot.value.diff);
const loading = computed(() => slot.value.loading);
const error = computed(() => slot.value.error);
const history = computed(() => slot.value.history);
const historyLoading = computed(() => slot.value.historyLoading);
const rollingBackVersion = ref<number | null>(null);

const onRollback = async (version: number): Promise<void> => {
  if (
    !window.confirm(
      `Roll back the draft to version ${version}? This loads the historic content into the draft — you'll still need to click Publish to make it live.`,
    )
  ) {
    return;
  }
  rollingBackVersion.value = version;
  try {
    await bannerStore.rollback(domainId, version);
    hydrateFromDraft();
    previewVersion.value++;
    toast.add({
      title: `Rolled back to v${version}`,
      description: 'Review the pending changes, then Publish to make it live.',
      color: 'success',
      icon: 'i-lucide-check',
    });
  } catch (caught) {
    toast.add({
      title: 'Rollback failed',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    rollingBackVersion.value = null;
  }
};

const state = reactive<BannerDraftInput>({
  layout: 'modal',
  granularity: 'categorised',
  locale_default: 'en',
  locales_enabled: ['en'],
  colors: {
    background: '#ffffff',
    text: '#111827',
    primary: '#2563eb',
    primaryText: '#ffffff',
    secondary: '#f3f4f6',
    secondaryText: '#111827',
  },
  copy: { en: defaultCopyForLocale() },
  gcm_defaults: {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    personalization_storage: 'denied',
    security_storage: 'granted',
  },
  geo_rules: { mode: 'always', countries: [] },
  behaviour: {
    rePromptAfterDays: 365,
    honourGpc: true,
    autoDenyOnDismiss: false,
    crossSubdomainConsent: false,
  },
});

const activeLocale = ref<string>('en');

const hydrateFromDraft = (): void => {
  if (!draft.value) return;
  state.layout = draft.value.layout;
  state.granularity = draft.value.granularity ?? 'categorised';
  state.locale_default = draft.value.localeDefault;
  state.locales_enabled = [...draft.value.localesEnabled];
  state.colors = { ...draft.value.colors };
  state.copy = Object.fromEntries(
    Object.entries(draft.value.copy).map(([code, copyValue]) => [
      code,
      {
        ...copyValue,
        categoryDescriptions: { ...copyValue.categoryDescriptions },
      },
    ]),
  );
  state.gcm_defaults = { ...draft.value.gcmDefaults };
  state.geo_rules = {
    ...draft.value.geoRules,
    countries: [...(draft.value.geoRules.countries ?? [])],
  };
  state.behaviour = { ...draft.value.behaviour };
  activeLocale.value = state.locale_default;
};

watch(draft, hydrateFromDraft, { immediate: true });

const addLocale = (code: string): void => {
  if (state.locales_enabled.includes(code)) return;
  state.locales_enabled = [...state.locales_enabled, code];
  if (!state.copy[code]) {
    state.copy = { ...state.copy, [code]: defaultCopyForLocale() };
  }
  activeLocale.value = code;
};

const removeLocale = (code: string): void => {
  if (code === state.locale_default) {
    toast.add({
      title: 'Cannot remove the default locale',
      description: 'Change the default locale first, then remove this one.',
      color: 'warning',
      icon: 'i-lucide-triangle-alert',
    });
    return;
  }
  state.locales_enabled = state.locales_enabled.filter((entry) => entry !== code);
  const { [code]: _removed, ...rest } = state.copy;
  state.copy = rest;
  if (activeLocale.value === code) {
    activeLocale.value = state.locale_default;
  }
};

const currentCopy = computed<BannerCopy | undefined>(() => state.copy[activeLocale.value]);

const updateCopy = (field: keyof BannerCopy, value: string): void => {
  const target = state.copy[activeLocale.value];
  if (!target) return;
  state.copy = {
    ...state.copy,
    [activeLocale.value]: { ...target, [field]: value },
  };
};

const updateCategoryDescription = (
  category: keyof BannerCopy['categoryDescriptions'],
  value: string,
): void => {
  const target = state.copy[activeLocale.value];
  if (!target) return;
  state.copy = {
    ...state.copy,
    [activeLocale.value]: {
      ...target,
      categoryDescriptions: { ...target.categoryDescriptions, [category]: value },
    },
  };
};

const saving = ref(false);
const publishing = ref(false);

// Cache-buster appended to the preview iframe URL — bumping it forces a
// reload so the iframe picks up the latest saved draft.
const previewVersion = ref(0);
const previewMode = ref<'desktop' | 'mobile'>('desktop');
const apiBase = useRuntimeConfig().public.apiBase;

const previewUrl = computed((): string => {
  return `${apiBase}/api/domains/${domainId}/banner-preview?v=${previewVersion.value}`;
});

const onSave = async (event: FormSubmitEvent<BannerDraftInput>): Promise<void> => {
  saving.value = true;
  try {
    await bannerStore.saveDraft(domainId, event.data);
    previewVersion.value++;
    toast.add({ title: 'Draft saved', color: 'success', icon: 'i-lucide-check' });
  } catch (caught) {
    toast.add({
      title: 'Save failed',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    saving.value = false;
  }
};

const onPublish = async (): Promise<void> => {
  if (!window.confirm('Publish this draft? The currently-published version will be archived.')) {
    return;
  }
  publishing.value = true;
  try {
    await bannerStore.saveDraft(domainId, state);
    await bannerStore.publish(domainId);
    toast.add({
      title: 'Banner published',
      description: 'Edge will pick up the new config on next request.',
      color: 'success',
      icon: 'i-lucide-rocket',
    });
  } catch (caught) {
    toast.add({
      title: 'Publish failed',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    publishing.value = false;
  }
};

const gcmSignals = [
  { key: 'analytics_storage', label: 'Analytics storage', hint: 'GA4, Hotjar, etc.' },
  { key: 'ad_storage', label: 'Ad storage', hint: 'Google Ads cookies' },
  { key: 'ad_user_data', label: 'Ad user data', hint: 'Sharing event data with Google for ads' },
  { key: 'ad_personalization', label: 'Ad personalisation', hint: 'Personalised ad targeting' },
  {
    key: 'personalization_storage',
    label: 'Personalisation storage',
    hint: 'Site-wide personalisation (recommendations)',
  },
  {
    key: 'functionality_storage',
    label: 'Functionality storage',
    hint: 'Essential session/preference storage',
  },
  { key: 'security_storage', label: 'Security storage', hint: 'Auth + anti-fraud cookies' },
] as const;
</script>

<template>
  <UDashboardNavbar :title="`Banner config · ${domainId.substring(0, 8)}…`">
    <template #leading>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
        :to="`/domains/${domainId}`"
      />
    </template>
    <template #right>
      <UBadge v-if="published" color="success" variant="subtle">
        Published v{{ published.version }}
      </UBadge>
      <UBadge v-else color="neutral" variant="subtle">Not yet published</UBadge>
      <div class="mx-1 h-6 w-px bg-accented" aria-hidden="true" />
      <OrgSwitcher />
    </template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-6">
    <div>
      <UAlert
        v-if="error"
        class="mb-4"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="error.message"
      />

      <USkeleton v-if="loading && !draft" class="h-64 w-full" />

      <UForm
        v-else-if="draft"
        :schema="bannerDraftSchema"
        :state="state"
        class="space-y-8"
        @submit="onSave"
      >
        <UCard>
          <template #header>
            <h3 class="font-semibold">Layout</h3>
          </template>
          <BannerLayoutPicker v-model="state.layout" />

          <div class="pt-6 mt-6 border-t border-gray-200 dark:border-gray-800 space-y-2">
            <p class="text-sm font-medium">Granularity</p>
            <p class="text-xs text-gray-500">
              Categorised gives the visitor per-category toggles (necessary / preferences /
              statistics / marketing). Basic collapses to a binary Accept / Reject and omits the
              customise button — useful when you only run one analytics tool and don't want to add
              UI friction for a meaningful choice.
            </p>
            <URadioGroup
              v-model="state.granularity"
              :items="[
                {
                  value: 'categorised',
                  label: 'Categorised (default)',
                  description:
                    'Per-category toggles with Accept-all, Reject-all, and Save-preferences.',
                },
                {
                  value: 'basic',
                  label: 'Basic',
                  description: 'Just Accept-all and Reject-all — no customise pane.',
                },
              ]"
            />
          </div>
        </UCard>

        <UCard>
          <template #header>
            <h3 class="font-semibold">Colours</h3>
          </template>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <BannerColorField
              v-model="state.colors.background"
              label="Background"
              name="colors.background"
            />
            <BannerColorField v-model="state.colors.text" label="Text" name="colors.text" />
            <BannerColorField
              v-model="state.colors.primary"
              label="Primary button"
              name="colors.primary"
            />
            <BannerColorField
              v-model="state.colors.primaryText"
              label="Primary button text"
              name="colors.primaryText"
            />
            <BannerColorField
              v-model="state.colors.secondary"
              label="Secondary button"
              name="colors.secondary"
            />
            <BannerColorField
              v-model="state.colors.secondaryText"
              label="Secondary button text"
              name="colors.secondaryText"
            />
          </div>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex items-center justify-between flex-wrap gap-3">
              <h3 class="font-semibold">Copy</h3>
              <div class="flex items-center gap-3">
                <span class="text-xs text-gray-500">Default locale</span>
                <USelect
                  v-model="state.locale_default"
                  :items="
                    state.locales_enabled.map((code) => ({ label: localeLabel(code), value: code }))
                  "
                  size="xs"
                />
                <UDropdownMenu
                  :items="
                    supportedLocales
                      .filter((entry) => !state.locales_enabled.includes(entry.code))
                      .map((entry) => ({
                        label: `${entry.emoji} ${entry.label}`,
                        onSelect: () => addLocale(entry.code),
                      }))
                  "
                >
                  <UButton size="xs" icon="i-lucide-plus" label="Add locale" />
                </UDropdownMenu>
              </div>
            </div>
          </template>

          <UTabs
            v-model="activeLocale"
            :items="
              state.locales_enabled.map((code) => ({ label: localeLabel(code), value: code }))
            "
            class="mb-4"
          />

          <template v-if="currentCopy">
            <div class="flex items-center justify-between mb-3">
              <p class="text-xs text-gray-500">
                Editing <code class="font-mono">{{ activeLocale }}</code>
                <span v-if="activeLocale === state.locale_default" class="ml-2 text-gray-400"
                  >(default)</span
                >
              </p>
              <UButton
                v-if="activeLocale !== state.locale_default"
                size="xs"
                variant="ghost"
                color="error"
                icon="i-lucide-trash-2"
                :label="`Remove ${activeLocale}`"
                @click="removeLocale(activeLocale)"
              />
            </div>

            <div class="space-y-4">
              <UFormField label="Title" :name="`copy.${activeLocale}.title`">
                <UInput
                  :model-value="currentCopy.title"
                  class="w-full"
                  @update:model-value="updateCopy('title', String($event))"
                />
              </UFormField>
              <UFormField
                label="Body"
                :name="`copy.${activeLocale}.body`"
                help="Recommended: under 300 characters."
              >
                <UTextarea
                  :model-value="currentCopy.body"
                  :rows="4"
                  class="w-full"
                  @update:model-value="updateCopy('body', String($event))"
                />
              </UFormField>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <UFormField label="Accept-all button" :name="`copy.${activeLocale}.acceptAllLabel`">
                  <UInput
                    :model-value="currentCopy.acceptAllLabel"
                    class="w-full"
                    @update:model-value="updateCopy('acceptAllLabel', String($event))"
                  />
                </UFormField>
                <UFormField label="Reject-all button" :name="`copy.${activeLocale}.rejectAllLabel`">
                  <UInput
                    :model-value="currentCopy.rejectAllLabel"
                    class="w-full"
                    @update:model-value="updateCopy('rejectAllLabel', String($event))"
                  />
                </UFormField>
                <UFormField label="Customise button" :name="`copy.${activeLocale}.customiseLabel`">
                  <UInput
                    :model-value="currentCopy.customiseLabel"
                    class="w-full"
                    @update:model-value="updateCopy('customiseLabel', String($event))"
                  />
                </UFormField>
                <UFormField
                  label="Save preferences button"
                  :name="`copy.${activeLocale}.savePreferencesLabel`"
                >
                  <UInput
                    :model-value="currentCopy.savePreferencesLabel"
                    class="w-full"
                    @update:model-value="updateCopy('savePreferencesLabel', String($event))"
                  />
                </UFormField>
              </div>

              <div class="space-y-4 pt-4 border-t border-gray-200 dark:border-gray-800">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                  Category descriptions
                </h4>
                <UFormField
                  label="Necessary"
                  :name="`copy.${activeLocale}.categoryDescriptions.necessary`"
                >
                  <UTextarea
                    :model-value="currentCopy.categoryDescriptions.necessary"
                    :rows="2"
                    class="w-full"
                    @update:model-value="updateCategoryDescription('necessary', String($event))"
                  />
                </UFormField>
                <UFormField
                  label="Preferences"
                  :name="`copy.${activeLocale}.categoryDescriptions.preferences`"
                >
                  <UTextarea
                    :model-value="currentCopy.categoryDescriptions.preferences"
                    :rows="2"
                    class="w-full"
                    @update:model-value="updateCategoryDescription('preferences', String($event))"
                  />
                </UFormField>
                <UFormField
                  label="Statistics"
                  :name="`copy.${activeLocale}.categoryDescriptions.statistics`"
                >
                  <UTextarea
                    :model-value="currentCopy.categoryDescriptions.statistics"
                    :rows="2"
                    class="w-full"
                    @update:model-value="updateCategoryDescription('statistics', String($event))"
                  />
                </UFormField>
                <UFormField
                  label="Marketing"
                  :name="`copy.${activeLocale}.categoryDescriptions.marketing`"
                >
                  <UTextarea
                    :model-value="currentCopy.categoryDescriptions.marketing"
                    :rows="2"
                    class="w-full"
                    @update:model-value="updateCategoryDescription('marketing', String($event))"
                  />
                </UFormField>
              </div>
            </div>
          </template>
        </UCard>

        <UCard>
          <template #header>
            <h3 class="font-semibold">Google Consent Mode v2 defaults</h3>
          </template>
          <p class="text-sm text-gray-500 mb-4">
            What gtag.js can do before the visitor has consented. Defaults are GDPR-safe (deny
            everything except functionality + security). Granting a signal before consent leaks
            tracking — only change these if a legal review demands it.
          </p>
          <div class="space-y-3">
            <div
              v-for="signal in gcmSignals"
              :key="signal.key"
              class="flex items-start justify-between gap-4 py-2"
            >
              <div class="flex-1">
                <p class="text-sm font-medium">{{ signal.label }}</p>
                <p class="text-xs text-gray-500">{{ signal.hint }}</p>
              </div>
              <USwitch
                :model-value="state.gcm_defaults[signal.key] === 'granted'"
                @update:model-value="state.gcm_defaults[signal.key] = $event ? 'granted' : 'denied'"
              />
            </div>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <h3 class="font-semibold">Geo rules</h3>
          </template>
          <UFormField label="When to show the banner" name="geo_rules.mode">
            <URadioGroup
              v-model="state.geo_rules.mode"
              :items="[
                {
                  value: 'always',
                  label: 'Always show',
                  description: 'Recommended unless you have a specific reason.',
                },
                {
                  value: 'eu_uk_only',
                  label: 'EU + UK only',
                  description: 'Skip the banner for visitors outside the EU/UK.',
                },
                {
                  value: 'whitelist',
                  label: 'Show only in selected countries',
                  description: 'Show for the country list below.',
                },
                {
                  value: 'blacklist',
                  label: 'Hide in selected countries',
                  description: 'Always show except for the country list below.',
                },
              ]"
            />
          </UFormField>

          <UFormField
            v-if="state.geo_rules.mode === 'whitelist' || state.geo_rules.mode === 'blacklist'"
            label="Countries"
            name="geo_rules.countries"
            class="mt-4"
            help="ISO 3166-1 alpha-2 country codes."
          >
            <USelectMenu
              v-model="state.geo_rules.countries"
              multiple
              searchable
              value-key="value"
              :items="
                geoCountries.map((country) => ({
                  label: `${country.code} — ${country.name}`,
                  value: country.code,
                }))
              "
              class="w-full"
            />
          </UFormField>
        </UCard>

        <UCard>
          <template #header>
            <h3 class="font-semibold">Behaviour</h3>
          </template>
          <div class="space-y-4">
            <UFormField
              label="Re-prompt after"
              name="behaviour.rePromptAfterDays"
              help="Show the banner again N days after the visitor's last consent."
            >
              <div class="flex items-center gap-2">
                <UInput
                  v-model="state.behaviour.rePromptAfterDays"
                  type="number"
                  :min="1"
                  :max="1825"
                  class="w-32"
                />
                <span class="text-sm text-gray-500">days</span>
              </div>
            </UFormField>

            <div class="flex items-start justify-between gap-4 py-2">
              <div class="flex-1">
                <p class="text-sm font-medium">Honour Global Privacy Control</p>
                <p class="text-xs text-gray-500">
                  Auto-deny non-necessary cookies if the visitor's browser sends the GPC header.
                </p>
              </div>
              <USwitch v-model="state.behaviour.honourGpc" />
            </div>

            <div class="flex items-start justify-between gap-4 py-2">
              <div class="flex-1">
                <p class="text-sm font-medium">Auto-deny on dismiss</p>
                <p class="text-xs text-gray-500">
                  Treat closing the banner without a choice as Reject-all. Some jurisdictions
                  require this.
                </p>
              </div>
              <USwitch v-model="state.behaviour.autoDenyOnDismiss" />
            </div>

            <div class="flex items-start justify-between gap-4 py-2">
              <div class="flex-1">
                <p class="text-sm font-medium">Share consent across subdomains</p>
                <p class="text-xs text-gray-500">
                  Write the consent cookie on the apex domain (e.g. .example.com) so all subdomains
                  share it.
                </p>
              </div>
              <USwitch v-model="state.behaviour.crossSubdomainConsent" />
            </div>
          </div>
        </UCard>

        <BannerPendingChangesPanel :diff="diff" />

        <BannerHistoryPanel
          :history="history"
          :loading="historyLoading"
          :rolling-back-version="rollingBackVersion"
          @rollback="onRollback"
        />

        <div class="flex justify-end gap-2">
          <UButton
            type="submit"
            :loading="saving"
            color="neutral"
            variant="subtle"
            label="Save draft"
            icon="i-lucide-save"
          />
          <UButton
            :loading="publishing"
            :color="diff?.isMaterial && !diff?.isFirstPublish ? 'warning' : 'primary'"
            icon="i-lucide-rocket"
            :label="
              diff?.isMaterial && !diff?.isFirstPublish
                ? 'Publish (re-prompts visitors)'
                : 'Publish'
            "
            @click="onPublish"
          />
        </div>
      </UForm>
    </div>

    <aside class="hidden xl:block">
      <div class="sticky top-4 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold">Preview</h3>
          <div class="flex items-center gap-2">
            <UButtonGroup size="xs">
              <UButton
                :variant="previewMode === 'desktop' ? 'solid' : 'soft'"
                icon="i-lucide-monitor"
                @click="previewMode = 'desktop'"
              />
              <UButton
                :variant="previewMode === 'mobile' ? 'solid' : 'soft'"
                icon="i-lucide-smartphone"
                @click="previewMode = 'mobile'"
              />
            </UButtonGroup>
            <UButton
              size="xs"
              variant="soft"
              icon="i-lucide-refresh-ccw"
              @click="previewVersion++"
            />
          </div>
        </div>

        <p class="text-xs text-gray-500">
          Saved drafts only — hit
          <span class="font-mono">Save draft</span> to update.
        </p>

        <div
          class="rounded-lg border border-gray-200 dark:border-gray-800 bg-gray-100 dark:bg-gray-900 overflow-hidden mx-auto"
          :class="previewMode === 'mobile' ? 'w-[375px]' : 'w-full'"
        >
          <iframe
            :src="previewUrl"
            :title="`Banner preview · ${domainId.substring(0, 8)}`"
            class="block w-full bg-white"
            :class="previewMode === 'mobile' ? 'h-[667px]' : 'h-[640px]'"
          />
        </div>
      </div>
    </aside>
  </div>
</template>

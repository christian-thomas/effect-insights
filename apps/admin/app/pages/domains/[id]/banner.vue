<script setup lang="ts">
import {
  bannerDraftSchema,
  type BannerDraftInput,
} from '@effect-cmp/types/schemas/banner';
import type { FormSubmitEvent } from '@nuxt/ui';

const route = useRoute();
const toast = useToast();
const bannerStore = useBannerConfigsStore();
const domainId = route.params.id as string;

await bannerStore.fetch(domainId);

const slot = computed(() => bannerStore.forDomain(domainId));
const draft = computed(() => slot.value.draft);
const published = computed(() => slot.value.published);
const loading = computed(() => slot.value.loading);
const error = computed(() => slot.value.error);

const state = reactive<BannerDraftInput>({
  layout: 'modal',
  colors: {
    background: '#ffffff',
    text: '#111827',
    primary: '#2563eb',
    primaryText: '#ffffff',
    secondary: '#f3f4f6',
    secondaryText: '#111827',
  },
  copy: {
    title: '',
    body: '',
    acceptAllLabel: '',
    rejectAllLabel: '',
    customiseLabel: '',
    savePreferencesLabel: '',
    categoryDescriptions: {
      necessary: '',
      preferences: '',
      statistics: '',
      marketing: '',
    },
  },
});

const hydrateFromDraft = (): void => {
  if (!draft.value) return;
  state.layout = draft.value.layout;
  state.colors = { ...draft.value.colors };
  state.copy = {
    ...draft.value.copy,
    categoryDescriptions: { ...draft.value.copy.categoryDescriptions },
  };
};

watch(draft, hydrateFromDraft, { immediate: true });

const saving = ref(false);
const publishing = ref(false);

const onSave = async (event: FormSubmitEvent<BannerDraftInput>): Promise<void> => {
  saving.value = true;
  try {
    await bannerStore.saveDraft(domainId, event.data);
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
    // Always persist the latest form state before publishing so the user
    // never accidentally publishes a stale draft.
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
    </template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8">
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
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Colours</h3>
        </template>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <BannerColorField v-model="state.colors.background" label="Background" name="colors.background" />
          <BannerColorField v-model="state.colors.text" label="Text" name="colors.text" />
          <BannerColorField v-model="state.colors.primary" label="Primary button" name="colors.primary" />
          <BannerColorField v-model="state.colors.primaryText" label="Primary button text" name="colors.primaryText" />
          <BannerColorField v-model="state.colors.secondary" label="Secondary button" name="colors.secondary" />
          <BannerColorField v-model="state.colors.secondaryText" label="Secondary button text" name="colors.secondaryText" />
        </div>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Copy</h3>
        </template>
        <div class="space-y-4">
          <UFormField label="Title" name="copy.title">
            <UInput v-model="state.copy.title" class="w-full" />
          </UFormField>
          <UFormField label="Body" name="copy.body" help="Recommended: under 300 characters.">
            <UTextarea v-model="state.copy.body" :rows="4" class="w-full" />
          </UFormField>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <UFormField label="Accept-all button" name="copy.acceptAllLabel">
              <UInput v-model="state.copy.acceptAllLabel" class="w-full" />
            </UFormField>
            <UFormField label="Reject-all button" name="copy.rejectAllLabel">
              <UInput v-model="state.copy.rejectAllLabel" class="w-full" />
            </UFormField>
            <UFormField label="Customise button" name="copy.customiseLabel">
              <UInput v-model="state.copy.customiseLabel" class="w-full" />
            </UFormField>
            <UFormField label="Save preferences button" name="copy.savePreferencesLabel">
              <UInput v-model="state.copy.savePreferencesLabel" class="w-full" />
            </UFormField>
          </div>
        </div>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Category descriptions</h3>
        </template>
        <div class="space-y-4">
          <UFormField label="Necessary" name="copy.categoryDescriptions.necessary">
            <UTextarea v-model="state.copy.categoryDescriptions.necessary" :rows="2" class="w-full" />
          </UFormField>
          <UFormField label="Preferences" name="copy.categoryDescriptions.preferences">
            <UTextarea v-model="state.copy.categoryDescriptions.preferences" :rows="2" class="w-full" />
          </UFormField>
          <UFormField label="Statistics" name="copy.categoryDescriptions.statistics">
            <UTextarea v-model="state.copy.categoryDescriptions.statistics" :rows="2" class="w-full" />
          </UFormField>
          <UFormField label="Marketing" name="copy.categoryDescriptions.marketing">
            <UTextarea v-model="state.copy.categoryDescriptions.marketing" :rows="2" class="w-full" />
          </UFormField>
        </div>
      </UCard>

      <div class="flex justify-end gap-2">
        <UButton type="submit" :loading="saving" color="neutral" variant="subtle" label="Save draft" icon="i-lucide-save" />
        <UButton :loading="publishing" icon="i-lucide-rocket" label="Publish" @click="onPublish" />
      </div>
    </UForm>
  </div>
</template>

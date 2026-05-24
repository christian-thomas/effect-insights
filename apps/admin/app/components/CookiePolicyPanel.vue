<script setup lang="ts">
import type { CookiePolicy } from '~/types/cookiePolicy';

const props = defineProps<{
  domainId: string;
  publicKey: string;
  fqdn: string;
}>();

const toast = useToast();
const store = useCookiePoliciesStore();
await store.fetch(props.domainId);

const slot = computed(() => store.forDomain(props.domainId));
const policy = computed<CookiePolicy | null>(() => slot.value.policy);
const loading = computed(() => slot.value.loading);
const error = computed(() => slot.value.error);

const apiBase = useRuntimeConfig().public.apiBase;
const publicUrl = computed((): string => `${apiBase}/api/cookie-policy/${props.publicKey}.html`);

const formatTimestamp = (iso: string | null): string => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
};

const totalCookies = computed((): number => {
  if (!policy.value) return 0;
  return policy.value.categories.reduce(
    (total, bucket): number => total + bucket.cookies.length,
    0,
  );
});

const unknownCount = computed((): number => {
  if (!policy.value) return 0;
  const unknownBucket = policy.value.categories.find((entry) => entry.category === 'unknown');
  return unknownBucket?.cookies.length ?? 0;
});

const iframeSnippet = computed(
  (): string => `<iframe
  src="${publicUrl.value}"
  title="Cookies in use on ${props.fqdn}"
  style="width: 100%; min-height: 600px; border: 0;"
></iframe>`,
);

const copyEmbed = async (): Promise<void> => {
  await navigator.clipboard.writeText(iframeSnippet.value);
  toast.add({ title: 'Embed snippet copied', color: 'success', icon: 'i-lucide-check' });
};

const refresh = async (): Promise<void> => {
  await store.fetch(props.domainId);
  toast.add({ title: 'Cookie policy refreshed', color: 'success', icon: 'i-lucide-check' });
};
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <div>
          <h3 class="font-semibold">Cookie policy</h3>
          <p v-if="policy?.scan" class="text-xs text-gray-500">
            Generated from scan {{ policy.scan.id.substring(0, 8) }}… ·
            {{ formatTimestamp(policy.scan.finishedAt) }}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <UButton
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-refresh-ccw"
            label="Refresh"
            :loading="loading"
            @click="refresh"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="soft"
            icon="i-lucide-external-link"
            label="Open public page"
            :to="publicUrl"
            target="_blank"
            rel="noopener"
          />
        </div>
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

    <USkeleton v-if="loading && !policy" class="h-32 w-full" />

    <template v-else-if="policy && policy.scan === null">
      <p class="text-sm text-gray-500 py-2">
        No scan has completed for this domain yet. Trigger a scan from the
        <NuxtLink :to="`/domains/${domainId}/scans`" class="underline">Scans</NuxtLink>
        page — the policy populates automatically once findings land.
      </p>
    </template>

    <template v-else-if="policy">
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="rounded border border-gray-200 dark:border-gray-800 p-3">
          <p class="text-xs text-gray-500">Total cookies</p>
          <p class="text-2xl font-semibold">{{ totalCookies }}</p>
        </div>
        <div
          v-for="bucket in policy.categories.filter((entry) => entry.category !== 'unknown')"
          :key="bucket.category"
          class="rounded border border-gray-200 dark:border-gray-800 p-3"
        >
          <p class="text-xs text-gray-500 capitalize">{{ bucket.category }}</p>
          <p class="text-2xl font-semibold">{{ bucket.cookies.length }}</p>
        </div>
      </div>

      <UAlert
        v-if="unknownCount > 0"
        class="mb-4"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="`${unknownCount} cookie${unknownCount === 1 ? '' : 's'} pending review`"
      >
        <template #description>
          They'll show in the policy under "Unknown" until you add a canonical entry or override.
          <NuxtLink to="/cookies?tab=review" class="underline">Open the review queue</NuxtLink>.
        </template>
      </UAlert>

      <details class="mb-4">
        <summary class="text-sm font-medium cursor-pointer select-none">
          View all cookies grouped by category
        </summary>
        <div class="mt-3 space-y-4">
          <div v-for="bucket in policy.categories" :key="bucket.category">
            <p class="text-sm font-medium capitalize">{{ bucket.label }}</p>
            <p class="text-xs text-gray-500 mb-2">{{ bucket.description }}</p>
            <ul class="text-sm border border-gray-200 dark:border-gray-800 rounded divide-y divide-gray-200 dark:divide-gray-800">
              <li
                v-for="cookie in bucket.cookies"
                :key="`${bucket.category}-${cookie.name}`"
                class="px-3 py-2 flex items-start gap-2"
              >
                <code class="text-xs flex-shrink-0">{{ cookie.name }}</code>
                <span class="text-xs text-gray-500">
                  {{ cookie.party }}-party
                  <span v-if="cookie.domainSetOn"> · {{ cookie.domainSetOn }}</span>
                </span>
                <span v-if="cookie.purpose" class="text-xs text-gray-500 ml-auto">
                  {{ cookie.purpose }}
                </span>
              </li>
            </ul>
          </div>
        </div>
      </details>

      <div>
        <div class="flex items-center justify-between mb-2">
          <p class="text-sm font-medium">Embed snippet</p>
          <UButton size="xs" icon="i-lucide-copy" label="Copy" @click="copyEmbed" />
        </div>
        <p class="text-xs text-gray-500 mb-2">
          Drop into the client's privacy page. The iframe renders the public HTML at
          <code class="break-all">{{ publicUrl }}</code> — keyed off the domain's public key so
          no tenant data is leaked.
        </p>
        <pre
          class="rounded bg-gray-50 dark:bg-gray-900 p-3 text-xs overflow-x-auto whitespace-pre-wrap break-all"
        ><code>{{ iframeSnippet }}</code></pre>
      </div>
    </template>
  </UCard>
</template>

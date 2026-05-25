<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import type { Organisation } from '~/types/organisation';

/**
 * Pill-style organisation switcher rendered in the navbar's #right slot.
 *
 * Sits outside the sidebar because the sidebar header is reserved for the
 * product brand row and we want the switcher consistently anchored to the
 * page-level toolbar. Multi-org users get a dropdown with a coloured avatar
 * per row + a check icon on the active org. Single-org users get a static
 * identity pill — the dropdown collapses to nothing useful when there's only
 * one option.
 */

const auth = useAuthStore();
const toast = useToast();

/**
 * Two uppercase letters from the org name: first letter of the first two
 * words, or the first two letters of a single-word name. Falls back to "?".
 */
const initialsFromName = (name: string): string => {
  const trimmed = name.trim();
  if (trimmed.length === 0) return '?';
  const words = trimmed.split(/\s+/);
  if (words.length === 1) {
    return (words[0] ?? '').slice(0, 2).toUpperCase();
  }
  const first = words[0] ?? '';
  const second = words[1] ?? '';
  return `${first.charAt(0)}${second.charAt(0)}`.toUpperCase();
};

/**
 * Deterministic hue from the org id via a tiny FNV-1a hash. Same id → same
 * hue, so a superuser hopping between tenants gets a stable visual anchor
 * even when org names look alike or change.
 */
const hueFromId = (id: string): number => {
  let hash = 2166136261;
  for (let index = 0; index < id.length; index += 1) {
    hash ^= id.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return Math.abs(hash) % 360;
};

const avatarStyle = (organisation: Organisation): Record<string, string> => {
  const hue = hueFromId(organisation.id);
  return {
    backgroundColor: `hsl(${hue} 65% 92%)`,
    color: `hsl(${hue} 55% 28%)`,
  };
};

const switchTo = async (organisationId: string): Promise<void> => {
  try {
    await auth.switchOrganisation(organisationId);
  } catch (caught) {
    toast.add({
      color: 'error',
      icon: 'i-lucide-triangle-alert',
      title: 'Could not switch organisation',
      description: caught instanceof Error ? caught.message : 'Please try again',
    });
  }
};

/**
 * Each row carries the full Organisation + an `isCurrent` flag — the custom
 * `#org-leading` / `#org-trailing` slots read them back to render the
 * coloured avatar and the active-row checkmark. Nuxt UI's slot props arrive
 * loosely typed, so `asOrgItem` narrows them in one place.
 */
interface OrgItem extends DropdownMenuItem {
  organisation: Organisation;
  isCurrent: boolean;
}

const asOrgItem = (item: unknown): OrgItem => item as OrgItem;

const orgItems = computed((): DropdownMenuItem[][] => {
  const currentId = auth.currentOrganisation?.id ?? null;
  const items: OrgItem[] = auth.organisations.map((organisation): OrgItem => {
    const isCurrent = organisation.id === currentId;
    return {
      label: organisation.name,
      slot: 'org',
      disabled: isCurrent,
      organisation,
      isCurrent,
      onSelect: isCurrent ? undefined : (): Promise<void> => switchTo(organisation.id),
    };
  });
  return [[{ label: 'Switch organisation', type: 'label' }], items];
});
</script>

<template>
  <div v-if="auth.currentOrganisation" class="min-w-0">
    <UDropdownMenu
      v-if="auth.organisations.length > 1"
      :items="orgItems"
      :content="{ align: 'end', sideOffset: 8 }"
      :ui="{ content: 'min-w-64' }"
    >
      <button
        type="button"
        class="group flex max-w-xs items-center gap-2 rounded-md border border-default bg-default px-2 py-1.5 text-left transition-colors hover:bg-elevated focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <span
          class="flex size-7 shrink-0 items-center justify-center rounded text-[11px] font-semibold"
          :style="avatarStyle(auth.currentOrganisation)"
        >
          {{ initialsFromName(auth.currentOrganisation.name) }}
        </span>
        <span class="min-w-0 flex-1">
          <span
            class="block text-[10px] font-medium uppercase tracking-wide leading-none text-muted"
          >
            Organisation
          </span>
          <span class="mt-0.5 block truncate text-sm font-medium leading-tight text-highlighted">
            {{ auth.currentOrganisation.name }}
          </span>
        </span>
        <UIcon
          name="i-lucide-chevrons-up-down"
          class="size-4 shrink-0 text-muted group-hover:text-default"
        />
      </button>

      <template #org-leading="{ item }">
        <span
          class="flex size-7 shrink-0 items-center justify-center rounded text-[11px] font-semibold"
          :style="avatarStyle(asOrgItem(item).organisation)"
        >
          {{ initialsFromName(asOrgItem(item).organisation.name) }}
        </span>
      </template>

      <template #org-trailing="{ item }">
        <UIcon v-if="asOrgItem(item).isCurrent" name="i-lucide-check" class="size-4 text-primary" />
      </template>
    </UDropdownMenu>

    <div
      v-else
      class="flex max-w-xs items-center gap-2 rounded-md border border-default bg-default px-2 py-1.5"
    >
      <span
        class="flex size-7 shrink-0 items-center justify-center rounded text-[11px] font-semibold"
        :style="avatarStyle(auth.currentOrganisation)"
      >
        {{ initialsFromName(auth.currentOrganisation.name) }}
      </span>
      <span class="min-w-0 flex-1">
        <span class="block text-[10px] font-medium uppercase tracking-wide leading-none text-muted">
          Organisation
        </span>
        <span class="mt-0.5 block truncate text-sm font-medium leading-tight text-highlighted">
          {{ auth.currentOrganisation.name }}
        </span>
      </span>
    </div>
  </div>
</template>

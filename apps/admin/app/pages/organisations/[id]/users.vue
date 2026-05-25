<script setup lang="ts">
import type {
  OrganisationMember,
  OrganisationRole,
  PendingInvitation,
} from '~/types/userManagement';

const auth = useAuthStore();

if (!auth.user?.isSuperuser) {
  throw createError({ statusCode: 403, statusMessage: 'Superuser role required' });
}

const route = useRoute();
const router = useRouter();
const store = useOrganisationMembersStore();
const orgsStore = useOrganisationsStore();
const toast = useToast();

const organisationId = route.params.id as string;

const { members, invitations, loading, saving } = storeToRefs(store);

const organisation = computed(
  () => orgsStore.items.find((row) => row.id === organisationId) ?? null,
);

const fetchAll = async (): Promise<void> => {
  await Promise.all([
    store.fetch(organisationId),
    orgsStore.items.length === 0 ? orgsStore.list() : Promise.resolve(),
  ]);
};

await fetchAll();

const roleOptions: Array<{ label: string; value: OrganisationRole }> = [
  { label: 'Owner', value: 'owner' },
  { label: 'Admin', value: 'admin' },
  { label: 'Viewer', value: 'viewer' },
];

interface InviteFormState {
  email: string;
  role: OrganisationRole;
}

const inviteForm = reactive<InviteFormState>({
  email: '',
  role: 'viewer',
});

/**
 * Pull the operator-facing message out of an Ofetch error. The Laravel
 * exception handler wraps abort(422, "...") + validation errors in a
 * `{ message, errors? }` JSON body — fall back to the bare Error.message
 * (e.g. "422 Unprocessable Entity") only when that's missing.
 */
const apiErrorMessage = (caught: unknown, fallback: string): string => {
  if (caught instanceof Error) {
    const data = (
      caught as Error & { data?: { message?: string; errors?: Record<string, string[]> } }
    ).data;
    if (data?.message) return data.message;
    if (data?.errors) {
      const firstFieldErrors = Object.values(data.errors)[0];
      if (firstFieldErrors?.[0]) return firstFieldErrors[0];
    }
    if (caught.message && !caught.message.match(/^\d{3}\s/)) return caught.message;
  }
  return fallback;
};

const onInvite = async (): Promise<void> => {
  try {
    await store.invite(organisationId, {
      email: inviteForm.email.trim(),
      role: inviteForm.role,
    });
    toast.add({
      title: `Invitation sent to ${inviteForm.email.trim()}`,
      icon: 'i-lucide-mail',
      color: 'success',
    });
    inviteForm.email = '';
    inviteForm.role = 'viewer';
  } catch (caught) {
    toast.add({
      title: 'Could not send invitation',
      description: apiErrorMessage(caught, 'Please try again'),
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  }
};

const revokeOpen = ref(false);
const revokeTarget = ref<PendingInvitation | null>(null);
const revoking = ref(false);

const onRevoke = (invitation: PendingInvitation): void => {
  revokeTarget.value = invitation;
  revokeOpen.value = true;
};

const confirmRevoke = async (close: () => void): Promise<void> => {
  const target = revokeTarget.value;
  if (target === null) {
    close();
    return;
  }
  revoking.value = true;
  try {
    await store.revokeInvitation(organisationId, target.id);
    toast.add({ title: 'Invitation revoked', icon: 'i-lucide-check', color: 'success' });
    close();
  } catch (caught) {
    toast.add({
      title: 'Could not revoke invitation',
      description: apiErrorMessage(caught, 'Please try again'),
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    revoking.value = false;
  }
};

const removeMemberOpen = ref(false);
const removeMemberTarget = ref<OrganisationMember | null>(null);
const removingMember = ref(false);

const removeIsSelf = computed((): boolean => removeMemberTarget.value?.id === auth.user?.id);

const removeMessage = computed((): string => {
  const target = removeMemberTarget.value;
  if (target === null) return '';
  if (removeIsSelf.value) {
    return `Remove yourself from this organisation? You'll lose access to its domains, scans, and consent log via the org switcher.`;
  }
  return `Remove ${target.name} (${target.email}) from this organisation? Their user account is preserved and any other organisations they belong to stay intact.`;
});

const removeTitle = computed((): string => {
  const target = removeMemberTarget.value;
  if (target === null) return 'Remove member?';
  if (removeIsSelf.value) return 'Leave this organisation?';
  return `Remove ${target.name}?`;
});

const removeConfirmLabel = computed((): string => (removeIsSelf.value ? 'Leave' : 'Remove'));

const onRemoveMember = (member: OrganisationMember): void => {
  removeMemberTarget.value = member;
  removeMemberOpen.value = true;
};

const confirmRemoveMember = async (close: () => void): Promise<void> => {
  const target = removeMemberTarget.value;
  if (target === null) {
    close();
    return;
  }
  const isSelf = target.id === auth.user?.id;
  removingMember.value = true;
  try {
    await store.removeMember(organisationId, target.id);
    toast.add({
      title: isSelf ? 'Left organisation' : `Removed ${target.name}`,
      icon: 'i-lucide-check',
      color: 'success',
    });
    close();
  } catch (caught) {
    toast.add({
      title: 'Could not remove member',
      description: apiErrorMessage(caught, 'Please try again'),
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    removingMember.value = false;
  }
};

const formatTime = (iso: string | null): string => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
};

const goBack = (): void => {
  router.push('/organisations');
};
</script>

<template>
  <UDashboardNavbar :title="`Users · ${organisation?.name ?? 'Organisation'}`">
    <template #leading>
      <UButton
        icon="i-lucide-arrow-left"
        color="neutral"
        variant="ghost"
        size="xs"
        @click="goBack"
      /> </template
  ></UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-5xl">
    <UCard>
      <template #header>
        <div>
          <h2 class="text-base font-semibold">Invite a user</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            The invitee receives an email with a magic link that expires in 7 days. They set their
            own name + password during sign-up. If the email already belongs to a user they're
            attached to this organisation without changing their existing credentials.
          </p>
        </div>
      </template>
      <UForm :state="inviteForm" class="space-y-4 max-w-xl" @submit="onInvite">
        <UFormField label="Email" name="email" required>
          <UInput
            v-model="inviteForm.email"
            type="email"
            placeholder="newperson@client.com"
            required
            class="w-full"
          />
        </UFormField>
        <UFormField label="Role" name="role">
          <USelectMenu
            v-model="inviteForm.role"
            :items="roleOptions"
            value-key="value"
            class="w-full sm:w-48"
          />
        </UFormField>
        <div class="pt-2">
          <UButton
            type="submit"
            icon="i-lucide-send"
            label="Send invitation"
            :loading="saving"
            :disabled="loading"
          />
        </div>
      </UForm>
    </UCard>

    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="font-semibold">Pending invitations</h3>
          <span class="text-sm text-gray-500">{{ invitations.length }} pending</span>
        </div>
      </template>
      <div v-if="invitations.length === 0" class="text-sm text-gray-500 py-2">
        No pending invitations.
      </div>
      <UTable
        v-else
        :data="invitations"
        :columns="[
          { accessorKey: 'email', header: 'Email' },
          { accessorKey: 'role', header: 'Role' },
          { accessorKey: 'expiresAt', header: 'Expires' },
          { accessorKey: 'createdAt', header: 'Sent' },
          { id: 'actions', header: '' },
        ]"
        class="w-full"
      >
        <template #email-cell="{ row }">
          <span class="text-sm">{{ row.original.email }}</span>
        </template>
        <template #role-cell="{ row }">
          <UBadge :label="row.original.role" variant="subtle" color="neutral" class="capitalize" />
        </template>
        <template #expiresAt-cell="{ row }">
          <span class="text-xs text-gray-500">{{ formatTime(row.original.expiresAt) }}</span>
        </template>
        <template #createdAt-cell="{ row }">
          <span class="text-xs text-gray-500">{{ formatTime(row.original.createdAt) }}</span>
        </template>
        <template #actions-cell="{ row }">
          <UButton
            size="xs"
            variant="ghost"
            color="error"
            icon="i-lucide-x"
            label="Revoke"
            @click="onRevoke(row.original)"
          />
        </template>
      </UTable>
    </UCard>

    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <h3 class="font-semibold">Members</h3>
          <span class="text-sm text-gray-500"
            >{{ members.length }} {{ members.length === 1 ? 'member' : 'members' }}</span
          >
        </div>
      </template>
      <UTable
        :data="members"
        :columns="[
          { accessorKey: 'name', header: 'Name' },
          { accessorKey: 'email', header: 'Email' },
          { accessorKey: 'role', header: 'Role' },
          { accessorKey: 'joinedAt', header: 'Joined' },
          { id: 'actions', header: '' },
        ]"
        class="w-full"
      >
        <template #name-cell="{ row }">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{ row.original.name }}</span>
            <UBadge
              v-if="row.original.id === auth.user?.id"
              variant="subtle"
              color="primary"
              size="sm"
              label="You"
            />
          </div>
        </template>
        <template #email-cell="{ row }">
          <span class="text-sm font-mono text-gray-700 dark:text-gray-300">{{
            row.original.email
          }}</span>
        </template>
        <template #role-cell="{ row }">
          <UBadge :label="row.original.role" variant="subtle" color="neutral" class="capitalize" />
        </template>
        <template #joinedAt-cell="{ row }">
          <span class="text-xs text-gray-500">{{ formatTime(row.original.joinedAt) }}</span>
        </template>
        <template #actions-cell="{ row }">
          <UTooltip
            v-if="row.original.id === auth.user?.id && members.length === 1"
            text="You're the last member of this organisation. Add another member before you can leave."
          >
            <UButton
              size="xs"
              variant="ghost"
              color="neutral"
              icon="i-lucide-user-x"
              label="Leave"
              disabled
            />
          </UTooltip>
          <UButton
            v-else
            size="xs"
            variant="ghost"
            color="error"
            icon="i-lucide-user-x"
            :label="row.original.id === auth.user?.id ? 'Leave' : 'Remove'"
            @click="onRemoveMember(row.original)"
          />
        </template>
      </UTable>
    </UCard>
  </div>

  <ConfirmModal
    v-model:open="revokeOpen"
    :title="revokeTarget ? `Revoke invitation for ${revokeTarget.email}?` : 'Revoke invitation?'"
    description="The pending magic link will stop working. You can re-invite this email at any time."
    confirm-label="Revoke"
    confirm-icon="i-lucide-x"
    cancel-label="Keep invitation"
    :loading="revoking"
    @confirm="confirmRevoke"
  />

  <ConfirmModal
    v-model:open="removeMemberOpen"
    :title="removeTitle"
    :description="removeMessage"
    :confirm-label="removeConfirmLabel"
    confirm-icon="i-lucide-user-x"
    :loading="removingMember"
    @confirm="confirmRemoveMember"
  />
</template>

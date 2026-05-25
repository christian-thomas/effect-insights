<script setup lang="ts">
import type { InvitationPreview } from '~/types/userManagement';
import type { Organisation } from '~/types/organisation';
import type { AuthUser } from '~/types/auth';

definePageMeta({ layout: 'blank' });

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const token = route.params.token as string;

const invitation = ref<InvitationPreview | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const submitting = ref(false);

interface SessionPayload {
  user: AuthUser;
  organisations: ReadonlyArray<Organisation>;
  currentOrganisationId: string | null;
}

interface AcceptForm {
  name: string;
  password: string;
  password_confirmation: string;
}

const form = reactive<AcceptForm>({
  name: '',
  password: '',
  password_confirmation: '',
});

const loadPreview = async (): Promise<void> => {
  loading.value = true;
  error.value = null;
  try {
    invitation.value = await $api<InvitationPreview>(`/api/invitations/${token}`);
  } catch (caught) {
    error.value =
      caught instanceof Error && 'data' in caught && typeof caught.data === 'object'
        ? // @ts-expect-error narrow at runtime via Ofetch shape
          (caught.data?.message ?? 'This invitation is invalid, expired, or already used.')
        : 'This invitation is invalid, expired, or already used.';
  } finally {
    loading.value = false;
  }
};

await loadPreview();

const onSubmit = async (): Promise<void> => {
  if (form.password !== form.password_confirmation) {
    toast.add({
      title: "Passwords don't match",
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
    return;
  }
  submitting.value = true;
  try {
    const response = await $api<SessionPayload>(`/api/invitations/${token}/accept`, {
      method: 'POST',
      body: {
        name: form.name.trim(),
        password: form.password,
        password_confirmation: form.password_confirmation,
      },
    });
    auth.hydrate(response);
    toast.add({
      title: `Welcome, ${response.user.name}`,
      description: `You're signed in to ${invitation.value?.organisation.name ?? 'your organisation'}.`,
      icon: 'i-lucide-check',
      color: 'success',
    });
    await router.push('/');
  } catch (caught) {
    toast.add({
      title: 'Could not complete sign-up',
      description: caught instanceof Error ? caught.message : 'Please try again',
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    submitting.value = false;
  }
};
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4 bg-gray-50 dark:bg-gray-950">
    <UCard class="w-full max-w-md">
      <template #header>
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-shield-check" class="text-primary-500 text-xl shrink-0" />
          <span class="font-semibold">Effect Insight</span>
        </div>
      </template>

      <div v-if="loading" class="space-y-3">
        <USkeleton class="h-6 w-3/4" />
        <USkeleton class="h-4 w-1/2" />
        <USkeleton class="h-32 w-full" />
      </div>

      <div v-else-if="error" class="space-y-3 text-center py-4">
        <UIcon name="i-lucide-x-circle" class="text-3xl text-red-500" />
        <h2 class="text-lg font-semibold">Invitation unavailable</h2>
        <p class="text-sm text-gray-500">{{ error }}</p>
        <UButton to="/login" label="Go to sign in" color="neutral" variant="ghost" />
      </div>

      <div v-else-if="invitation" class="space-y-5">
        <div>
          <h2 class="text-lg font-semibold">
            You're invited to {{ invitation.organisation.name }}
          </h2>
          <p class="text-sm text-gray-500 mt-1">
            Set up your account to accept the invitation. After sign-up you'll land in the admin
            for <strong>{{ invitation.organisation.name }}</strong> as a
            <strong class="capitalize">{{ invitation.role }}</strong>.
          </p>
        </div>

        <UForm :state="form" class="space-y-4" @submit="onSubmit">
          <UFormField label="Email">
            <UInput :model-value="invitation.email" type="email" disabled />
          </UFormField>
          <UFormField label="Your name" name="name" required>
            <UInput v-model="form.name" type="text" autocomplete="name" required />
          </UFormField>
          <UFormField label="Password" name="password" required>
            <UInput
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
            />
          </UFormField>
          <UFormField label="Confirm password" name="password_confirmation" required>
            <UInput
              v-model="form.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              minlength="8"
            />
          </UFormField>
          <UButton
            type="submit"
            block
            icon="i-lucide-check"
            label="Accept invitation + sign in"
            :loading="submitting"
          />
        </UForm>

        <p class="text-xs text-gray-500 text-center">
          You can register a passkey from your profile once you're signed in.
        </p>
      </div>
    </UCard>
  </div>
</template>

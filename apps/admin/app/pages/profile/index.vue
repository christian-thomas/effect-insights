<script setup lang="ts">
interface ProfileLink {
  to: string;
  label: string;
  description: string;
  icon: string;
}

const auth = useAuthStore();
const toast = useToast();

interface ProfileForm {
  name: string;
  email: string;
}

const profileForm = reactive<ProfileForm>({
  name: auth.user?.name ?? '',
  email: auth.user?.email ?? '',
});
const savingProfile = ref(false);

const onSubmitProfile = async (): Promise<void> => {
  savingProfile.value = true;
  try {
    await auth.updateProfile({
      name: profileForm.name.trim(),
      email: profileForm.email.trim(),
    });
    toast.add({
      title: 'Profile updated',
      icon: 'i-lucide-check',
      color: 'success',
    });
  } catch (caught) {
    toast.add({
      title: 'Could not update profile',
      description: caught instanceof Error ? caught.message : 'Please try again',
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    savingProfile.value = false;
  }
};

interface PasswordForm {
  current_password: string;
  password: string;
  password_confirmation: string;
}

const passwordForm = reactive<PasswordForm>({
  current_password: '',
  password: '',
  password_confirmation: '',
});
const savingPassword = ref(false);

const onSubmitPassword = async (): Promise<void> => {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    toast.add({
      title: "New passwords don't match",
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
    return;
  }

  savingPassword.value = true;
  try {
    await auth.changePassword({
      current_password: passwordForm.current_password,
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    });
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
    toast.add({
      title: 'Password changed',
      description: 'Existing sessions stay signed in.',
      icon: 'i-lucide-check',
      color: 'success',
    });
  } catch (caught) {
    toast.add({
      title: 'Could not change password',
      description: caught instanceof Error ? caught.message : 'Please try again',
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    savingPassword.value = false;
  }
};

const links: ProfileLink[] = [
  {
    to: '/profile/passkeys',
    label: 'Passkeys',
    description:
      'Register TouchID / Windows Hello / YubiKey credentials and use them to sign in without a password.',
    icon: 'i-lucide-fingerprint',
  },
];
</script>

<template>
  <UDashboardNavbar title="Profile">
    <template #right>
      <OrgSwitcher />
    </template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 max-w-3xl space-y-6">
    <UCard>
      <template #header>
        <h3 class="font-semibold">Account</h3>
      </template>
      <UForm :state="profileForm" class="space-y-4" @submit="onSubmitProfile">
        <UFormField label="Name" name="name">
          <UInput v-model="profileForm.name" type="text" required />
        </UFormField>
        <UFormField
          label="Email"
          name="email"
          help="Changing your email immediately becomes your new sign-in identifier."
        >
          <UInput v-model="profileForm.email" type="email" required />
        </UFormField>
        <div v-if="auth.user?.isSuperuser">
          <UBadge color="primary" variant="subtle" label="Superuser" />
        </div>
        <div class="flex justify-end">
          <UButton type="submit" icon="i-lucide-save" label="Save" :loading="savingProfile" />
        </div>
      </UForm>
    </UCard>

    <UCard>
      <template #header>
        <h3 class="font-semibold">Change password</h3>
      </template>
      <UForm :state="passwordForm" class="space-y-4" @submit="onSubmitPassword">
        <UFormField label="Current password" name="current_password" required>
          <UInput
            v-model="passwordForm.current_password"
            type="password"
            autocomplete="current-password"
            required
          />
        </UFormField>
        <UFormField label="New password" name="password" required>
          <UInput
            v-model="passwordForm.password"
            type="password"
            autocomplete="new-password"
            required
            minlength="8"
          />
        </UFormField>
        <UFormField label="Confirm new password" name="password_confirmation" required>
          <UInput
            v-model="passwordForm.password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            minlength="8"
          />
        </UFormField>
        <p class="text-xs text-gray-500">
          Changing your password doesn't sign you out of other sessions — revoke them by signing out
          from those browsers, or sign out and back in here to refresh the active session.
        </p>
        <div class="flex justify-end">
          <UButton
            type="submit"
            icon="i-lucide-key"
            label="Change password"
            :loading="savingPassword"
          />
        </div>
      </UForm>
    </UCard>

    <div>
      <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">
        Sign-in methods
      </h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <NuxtLink v-for="link in links" :key="link.to" :to="link.to" class="group">
          <UCard class="transition-shadow group-hover:shadow-md h-full">
            <div class="flex items-start gap-3">
              <UIcon :name="link.icon" class="text-2xl text-primary-500" />
              <div>
                <p class="font-semibold">{{ link.label }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ link.description }}</p>
              </div>
            </div>
          </UCard>
        </NuxtLink>
      </div>
    </div>
  </div>
</template>

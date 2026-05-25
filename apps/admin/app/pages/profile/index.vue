<script setup lang="ts">
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
</script>

<template>
  <UDashboardNavbar title="Profile"></UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 max-w-4xl">
    <UCard>
      <template #header>
        <div>
          <h2 class="text-base font-semibold">Your profile</h2>
          <p class="text-sm text-gray-500 mt-0.5">
            Account details and sign-in methods. Profile changes apply immediately.
          </p>
        </div>
      </template>

      <div class="divide-y divide-gray-200 dark:divide-gray-800">
        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6 first:pt-0">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-medium">Account</h3>
              <UBadge
                v-if="auth.user?.isSuperuser"
                color="primary"
                variant="subtle"
                size="sm"
                label="Superuser"
              />
            </div>
            <p class="text-sm text-gray-500 mt-1">
              Your name and email. Changing the email updates your sign-in identifier.
            </p>
          </div>
          <div class="md:col-span-2">
            <UForm :state="profileForm" class="space-y-4" @submit="onSubmitProfile">
              <UFormField label="Name" name="name" required>
                <UInput v-model="profileForm.name" type="text" required class="w-full sm:max-w-md" />
              </UFormField>
              <UFormField label="Email" name="email" required>
                <UInput v-model="profileForm.email" type="email" required class="w-full sm:max-w-md" />
              </UFormField>
              <div class="flex justify-end">
                <UButton type="submit" icon="i-lucide-save" label="Save" :loading="savingProfile" />
              </div>
            </UForm>
          </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6">
          <div>
            <h3 class="font-medium">Password</h3>
            <p class="text-sm text-gray-500 mt-1">
              Set a new password. Existing sessions stay signed in — sign out manually if you want
              to invalidate other browsers.
            </p>
          </div>
          <div class="md:col-span-2">
            <UForm :state="passwordForm" class="space-y-4" @submit="onSubmitPassword">
              <UFormField label="Current password" name="current_password" required>
                <UInput
                  v-model="passwordForm.current_password"
                  type="password"
                  autocomplete="current-password"
                  required
                  class="w-full sm:max-w-md"
                />
              </UFormField>
              <UFormField label="New password" name="password" required>
                <UInput
                  v-model="passwordForm.password"
                  type="password"
                  autocomplete="new-password"
                  required
                  minlength="8"
                  class="w-full sm:max-w-md"
                />
              </UFormField>
              <UFormField label="Confirm new password" name="password_confirmation" required>
                <UInput
                  v-model="passwordForm.password_confirmation"
                  type="password"
                  autocomplete="new-password"
                  required
                  minlength="8"
                  class="w-full sm:max-w-md"
                />
              </UFormField>
              <div class="flex justify-end">
                <UButton
                  type="submit"
                  icon="i-lucide-key"
                  label="Change password"
                  :loading="savingPassword"
                />
              </div>
            </UForm>
          </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-6 py-6">
          <div>
            <h3 class="font-medium">Passkeys</h3>
            <p class="text-sm text-gray-500 mt-1">
              Sign in without a password using TouchID, Windows Hello, a YubiKey, or any
              WebAuthn-compatible authenticator.
            </p>
          </div>
          <div class="md:col-span-2">
            <NuxtLink
              to="/profile/passkeys"
              class="flex items-center gap-3 p-4 rounded-lg border border-gray-200 dark:border-gray-800 hover:border-primary-300 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors group"
            >
              <UIcon
                name="i-lucide-fingerprint"
                class="text-2xl text-primary-500 shrink-0"
              />
              <div class="flex-1 min-w-0">
                <p class="font-medium">Manage passkeys</p>
                <p class="text-sm text-gray-500">
                  Register, label, and revoke credentials.
                </p>
              </div>
              <UIcon
                name="i-lucide-chevron-right"
                class="text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300 shrink-0"
              />
            </NuxtLink>
          </div>
        </section>
      </div>
    </UCard>
  </div>
</template>

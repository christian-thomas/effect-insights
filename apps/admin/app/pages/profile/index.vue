<script setup lang="ts">
interface ProfileLink {
  to: string;
  label: string;
  description: string;
  icon: string;
}

const auth = useAuthStore();

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
  <UDashboardNavbar title="Profile" />

  <div class="p-4 sm:p-6 lg:p-8 space-y-6">
    <UCard>
      <template #header>
        <h3 class="font-semibold">Account</h3>
      </template>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6 text-sm">
        <div>
          <dt class="text-gray-500">Name</dt>
          <dd class="font-medium">{{ auth.user?.name ?? '—' }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">Email</dt>
          <dd class="font-mono break-all">{{ auth.user?.email ?? '—' }}</dd>
        </div>
        <div v-if="auth.user?.isSuperuser" class="sm:col-span-2">
          <dt class="text-gray-500">Role</dt>
          <dd>
            <UBadge color="primary" variant="subtle" label="Superuser" />
          </dd>
        </div>
      </dl>
      <p class="mt-4 text-xs text-gray-500">
        Editing your name / email isn't wired into the admin yet — ask a superuser to update the
        record directly if you need a change.
      </p>
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

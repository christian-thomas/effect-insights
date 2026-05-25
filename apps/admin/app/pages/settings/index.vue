<script setup lang="ts">
interface SettingsLink {
  to: string;
  label: string;
  description: string;
  icon: string;
}

const links: SettingsLink[] = [
  {
    to: '/settings/passkeys',
    label: 'Passkeys',
    description:
      'Register TouchID / Windows Hello / YubiKey credentials and use them to sign in without a password.',
    icon: 'i-lucide-fingerprint',
  },
];
</script>

<template>
  <UDashboardNavbar title="Settings" />

  <div class="p-4 sm:p-6 lg:p-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
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
</template>

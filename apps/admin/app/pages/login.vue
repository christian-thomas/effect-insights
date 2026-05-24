<script setup lang="ts">
import { z } from 'zod';
import type { FormSubmitEvent } from '@nuxt/ui';

definePageMeta({ layout: 'blank' });

const schema = z.object({
  email: z.email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
});

type LoginForm = z.infer<typeof schema>;

const state = reactive<Partial<LoginForm>>({ email: '', password: '' });
const error = ref<string | null>(null);
const loading = ref(false);
const auth = useAuthStore();
const route = useRoute();

const onSubmit = async (event: FormSubmitEvent<LoginForm>) => {
  error.value = null;
  loading.value = true;
  try {
    await auth.login(event.data.email, event.data.password);
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
    await navigateTo(redirect);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : 'Sign in failed — try again';
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <UCard class="w-full max-w-md">
    <template #header>
      <div class="flex items-center gap-2">
        <UIcon name="i-lucide-shield-check" class="text-primary-500 text-2xl" />
        <h1 class="text-lg font-semibold">Effect Insight</h1>
      </div>
    </template>

    <UForm :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
      <UFormField label="Email" name="email">
        <UInput v-model="state.email" type="email" autocomplete="email" class="w-full" />
      </UFormField>

      <UFormField label="Password" name="password">
        <UInput
          v-model="state.password"
          type="password"
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="error"
      />

      <UButton type="submit" :loading="loading" block label="Sign in" />
    </UForm>
  </UCard>
</template>

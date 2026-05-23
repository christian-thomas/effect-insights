import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { mockNuxtImport } from '@nuxt/test-utils/runtime';

const apiMock = vi.hoisted(() => vi.fn());

mockNuxtImport('$api', () => apiMock);

import { useAuthStore } from '~/stores/auth';

const effectOrg = {
  id: '019e5097-7f34-72a3-990e-f8bc4c749f1a',
  name: 'Effect Digital',
  slug: 'effect',
};
const otherOrg = {
  id: '019e5097-8000-72a3-990e-f8bc4c749f1b',
  name: 'Other Client',
  slug: 'other',
};

const sessionPayload = (currentOrganisationId: string | null = effectOrg.id) => ({
  user: {
    id: '019e5097-7ffb-7092-9edc-b448a521abd7',
    name: 'Christian',
    email: 'me@christianthomas.co.uk',
    isSuperuser: true,
  },
  organisations: [effectOrg, otherOrg],
  currentOrganisationId,
});

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    apiMock.mockReset();
  });

  it('starts unauthenticated', () => {
    const auth = useAuthStore();
    expect(auth.isAuthenticated).toBe(false);
    expect(auth.user).toBeNull();
    expect(auth.currentOrganisation).toBeNull();
    expect(auth.organisationCacheKey).toBe('none');
  });

  it('hydrates state from /api/login response', async () => {
    apiMock.mockResolvedValueOnce(sessionPayload());

    const auth = useAuthStore();
    await auth.login('me@christianthomas.co.uk', 'secret');

    expect(auth.isAuthenticated).toBe(true);
    expect(auth.user?.email).toBe('me@christianthomas.co.uk');
    expect(auth.organisations).toHaveLength(2);
    expect(auth.currentOrganisation?.id).toBe(effectOrg.id);
    expect(auth.organisationCacheKey).toBe(effectOrg.id);
    expect(apiMock).toHaveBeenCalledWith('/api/login', {
      method: 'POST',
      body: { email: 'me@christianthomas.co.uk', password: 'secret' },
    });
  });

  it('uses currentOrganisationId from the server when hydrating', async () => {
    apiMock.mockResolvedValueOnce(sessionPayload(otherOrg.id));

    const auth = useAuthStore();
    await auth.fetchSession();

    expect(auth.currentOrganisation?.id).toBe(otherOrg.id);
  });

  it('updates current organisation on switch', async () => {
    apiMock
      .mockResolvedValueOnce(sessionPayload(effectOrg.id))
      .mockResolvedValueOnce(sessionPayload(otherOrg.id));

    const auth = useAuthStore();
    await auth.login('a@b', 'c');
    expect(auth.organisationCacheKey).toBe(effectOrg.id);

    await auth.switchOrganisation(otherOrg.id);
    expect(auth.currentOrganisation?.id).toBe(otherOrg.id);
    expect(auth.organisationCacheKey).toBe(otherOrg.id);
    expect(apiMock).toHaveBeenLastCalledWith('/api/organisations/switch', {
      method: 'POST',
      body: { organisation_id: otherOrg.id },
    });
  });

  it('clears state on logout even if the API call fails', async () => {
    apiMock.mockResolvedValueOnce(sessionPayload()).mockRejectedValueOnce(new Error('boom'));

    const auth = useAuthStore();
    await auth.login('x@y', 'p');
    expect(auth.isAuthenticated).toBe(true);

    await auth.logout();
    expect(auth.isAuthenticated).toBe(false);
    expect(auth.organisations).toHaveLength(0);
    expect(auth.organisationCacheKey).toBe('none');
  });
});

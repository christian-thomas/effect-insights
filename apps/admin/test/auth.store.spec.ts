import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { mockNuxtImport } from '@nuxt/test-utils/runtime';

const apiMock = vi.hoisted(() => vi.fn());

mockNuxtImport('$api', () => apiMock);

import { useAuthStore } from '~/stores/auth';

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
  });

  it('populates user + organisations on login', async () => {
    apiMock.mockResolvedValueOnce({
      user: {
        id: 1,
        name: 'Christian',
        email: 'me@christianthomas.co.uk',
        isSuperuser: true,
      },
      organisations: [
        { id: 10, name: 'Effect Digital', slug: 'effect' },
        { id: 11, name: 'Other Client', slug: 'other' },
      ],
    });

    const auth = useAuthStore();
    await auth.login('me@christianthomas.co.uk', 'secret');

    expect(auth.isAuthenticated).toBe(true);
    expect(auth.user?.email).toBe('me@christianthomas.co.uk');
    expect(auth.organisations).toHaveLength(2);
    expect(auth.currentOrganisation?.slug).toBe('effect');
    expect(apiMock).toHaveBeenCalledWith('/api/login', {
      method: 'POST',
      body: { email: 'me@christianthomas.co.uk', password: 'secret' },
    });
  });

  it('clears state on logout even if the API call fails', async () => {
    apiMock
      .mockResolvedValueOnce({
        user: { id: 1, name: 'X', email: 'x@y', isSuperuser: false },
        organisations: [{ id: 10, name: 'Org', slug: 'org' }],
      })
      .mockRejectedValueOnce(new Error('boom'));

    const auth = useAuthStore();
    await auth.login('x@y', 'p');
    expect(auth.isAuthenticated).toBe(true);

    await auth.logout();
    expect(auth.isAuthenticated).toBe(false);
    expect(auth.organisations).toHaveLength(0);
  });
});

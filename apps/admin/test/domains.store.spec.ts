import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { mockNuxtImport } from '@nuxt/test-utils/runtime';

const apiMock = vi.hoisted(() => vi.fn());

mockNuxtImport('$api', () => apiMock);

import { useDomainsStore } from '~/stores/domains';

const sampleDomain = {
  id: '019e5097-0000-0000-0000-000000000001',
  fqdn: 'effectdigital.com',
  apexDomain: 'effectdigital.com',
  status: 'active' as const,
  publicKey: 'pk_test_abc',
  settings: null,
  createdAt: '2026-05-22T00:00:00Z',
  updatedAt: '2026-05-22T00:00:00Z',
};

describe('domains store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    apiMock.mockReset();
  });

  it('lists domains', async () => {
    apiMock.mockResolvedValueOnce({ data: [sampleDomain] });

    const store = useDomainsStore();
    await store.list();

    expect(store.items).toHaveLength(1);
    expect(store.items[0]?.fqdn).toBe('effectdigital.com');
    expect(store.loading).toBe(false);
    expect(apiMock).toHaveBeenCalledWith('/api/domains');
  });

  it('captures errors on list', async () => {
    apiMock.mockRejectedValueOnce(new Error('boom'));

    const store = useDomainsStore();
    await store.list();

    expect(store.error?.message).toBe('boom');
  });

  it('creates a domain and refetches', async () => {
    apiMock
      .mockResolvedValueOnce({ data: sampleDomain })
      .mockResolvedValueOnce({ data: [sampleDomain] });

    const store = useDomainsStore();
    const created = await store.create({ fqdn: 'effectdigital.com' });

    expect(created.fqdn).toBe('effectdigital.com');
    expect(store.items).toHaveLength(1);
    expect(apiMock).toHaveBeenNthCalledWith(1, '/api/domains', {
      method: 'POST',
      body: { fqdn: 'effectdigital.com' },
    });
    expect(apiMock).toHaveBeenNthCalledWith(2, '/api/domains');
  });

  it('removes a domain and refetches', async () => {
    apiMock
      .mockResolvedValueOnce(undefined)
      .mockResolvedValueOnce({ data: [] });

    const store = useDomainsStore();
    await store.remove(sampleDomain.id);

    expect(store.items).toHaveLength(0);
    expect(apiMock).toHaveBeenNthCalledWith(1, `/api/domains/${sampleDomain.id}`, {
      method: 'DELETE',
    });
  });

  it('exposes findById getter', async () => {
    apiMock.mockResolvedValueOnce({ data: [sampleDomain] });

    const store = useDomainsStore();
    await store.list();

    expect(store.findById(sampleDomain.id)?.fqdn).toBe('effectdigital.com');
    expect(store.findById('missing')).toBeNull();
  });
});

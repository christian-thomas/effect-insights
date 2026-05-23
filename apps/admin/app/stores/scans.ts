import { defineStore } from 'pinia';
import type { Scan, ScanFinding } from '~/types/scans';

interface PaginatedResponse<T> {
  data: T[];
}

interface ScanDetailResponse {
  scan: { data: Scan };
  findings: { data: ScanFinding[] };
}

interface ScansState {
  scans: Scan[];
  selected: Scan | null;
  findings: ScanFinding[];
  loading: boolean;
  triggering: boolean;
  error: Error | null;
}

export const useScansStore = defineStore('scans', {
  state: (): ScansState => ({
    scans: [],
    selected: null,
    findings: [],
    loading: false,
    triggering: false,
    error: null,
  }),

  actions: {
    async list(domainId?: string): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const query = domainId ? `?domain_id=${encodeURIComponent(domainId)}` : '';
        const response = await $api<PaginatedResponse<Scan>>(`/api/scans${query}`);
        this.scans = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async show(scanId: string): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<ScanDetailResponse>(`/api/scans/${scanId}`);
        this.selected = response.scan.data;
        this.findings = response.findings.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async trigger(domainId: string): Promise<Scan> {
      this.triggering = true;
      try {
        const response = await $api<{ data: Scan }>(`/api/domains/${domainId}/scans`, {
          method: 'POST',
        });
        await this.list();
        return response.data;
      } finally {
        this.triggering = false;
      }
    },
  },
});

import { defineStore } from 'pinia';
import type { Scan, ScanFinding } from '~/types/scans';
import {
  emptyPagination,
  metaToPagination,
  type PaginatedResponse,
  type Pagination,
} from '~/types/pagination';

export interface ScanDiffEntry {
  name: string;
  category: string | null;
  firstSeenUrl: string | null;
}

export interface ScanCategoryChange {
  name: string;
  previousCategory: string | null;
  currentCategory: string | null;
}

export interface ScanDiff {
  previousScanId: string;
  previousFinishedAt: string | null;
  new: ScanDiffEntry[];
  removed: ScanDiffEntry[];
  categoryChanged: ScanCategoryChange[];
}

interface ScanDetailResponse {
  scan: { data: Scan };
  findings: { data: ScanFinding[] };
  diff: ScanDiff | null;
}

interface ScansState {
  scans: Scan[];
  selected: Scan | null;
  findings: ScanFinding[];
  diff: ScanDiff | null;
  pagination: Pagination;
  domainFilter: string | undefined;
  currentPage: number;
  loading: boolean;
  triggering: boolean;
  error: Error | null;
}

export const useScansStore = defineStore('scans', {
  state: (): ScansState => ({
    scans: [],
    selected: null,
    findings: [],
    diff: null,
    pagination: emptyPagination(25),
    domainFilter: undefined,
    currentPage: 1,
    loading: false,
    triggering: false,
    error: null,
  }),

  actions: {
    async list(domainId?: string, page?: number): Promise<void> {
      if (domainId !== undefined && domainId !== this.domainFilter) {
        this.domainFilter = domainId;
        this.currentPage = 1;
      }
      if (page !== undefined) {
        this.currentPage = page;
      }
      this.loading = true;
      this.error = null;
      try {
        const params = new URLSearchParams();
        if (this.domainFilter) params.set('domain_id', this.domainFilter);
        if (this.currentPage > 1) params.set('page', String(this.currentPage));
        const query = params.toString();
        const response = await $api<PaginatedResponse<Scan>>(
          `/api/scans${query ? `?${query}` : ''}`,
        );
        this.scans = response.data;
        this.pagination = metaToPagination(response, 25);
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
        this.diff = response.diff;
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
        this.currentPage = 1;
        await this.list();
        return response.data;
      } finally {
        this.triggering = false;
      }
    },
  },
});

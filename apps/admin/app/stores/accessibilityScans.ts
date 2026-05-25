import { defineStore } from 'pinia';
import type { AccessibilityFinding, AccessibilityScan } from '~/types/accessibility';
import {
  emptyPagination,
  metaToPagination,
  type PaginatedResponse,
  type Pagination,
} from '~/types/pagination';

interface ItemResponse {
  data: AccessibilityScan;
}

interface DetailResponse {
  scan: { data: AccessibilityScan };
  findings: { data: AccessibilityFinding[] };
}

interface AccessibilityScansState {
  scans: AccessibilityScan[];
  selected: AccessibilityScan | null;
  findings: AccessibilityFinding[];
  pagination: Pagination;
  domainFilter: string | undefined;
  currentPage: number;
  loading: boolean;
  triggering: boolean;
  error: Error | null;
}

/**
 * Pinia store for the accessibility scan resource. Mirrors the cookie
 * scans store (apps/admin/app/stores/scans.ts) — same list/show/trigger
 * shape so the UI feels identical between the two verticals.
 */
export const useAccessibilityScansStore = defineStore('accessibilityScans', {
  state: (): AccessibilityScansState => ({
    scans: [],
    selected: null,
    findings: [],
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
        const response = await $api<PaginatedResponse<AccessibilityScan>>(
          `/api/accessibility-scans${query ? `?${query}` : ''}`,
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
        const response = await $api<DetailResponse>(`/api/accessibility-scans/${scanId}`);
        this.selected = response.scan.data;
        this.findings = response.findings.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async trigger(domainId: string): Promise<AccessibilityScan> {
      this.triggering = true;
      try {
        const response = await $api<ItemResponse>(`/api/domains/${domainId}/accessibility-scans`, {
          method: 'POST',
        });
        // Endpoint returns the queued scan (status=queued). The runner
        // promotes it to completed/failed asynchronously via the
        // accessibility-scans Horizon queue. Caller polls show() for
        // status updates.
        return response.data;
      } finally {
        this.triggering = false;
      }
    },
  },
});

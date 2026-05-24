import { defineStore } from 'pinia';
import type {
  AccessibilityFinding,
  AccessibilityScan,
} from '~/types/accessibility';

interface ListResponse {
  data: AccessibilityScan[];
}

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
        const response = await $api<ListResponse>(`/api/accessibility-scans${query}`);
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
        const response = await $api<ItemResponse>(
          `/api/domains/${domainId}/accessibility-scans`,
          { method: 'POST' },
        );
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

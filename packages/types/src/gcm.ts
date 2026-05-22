export type GcmSignal = 'granted' | 'denied';

export interface GcmDefaults {
  readonly ad_storage: GcmSignal;
  readonly analytics_storage: GcmSignal;
  readonly ad_user_data: GcmSignal;
  readonly ad_personalization: GcmSignal;
  readonly functionality_storage: GcmSignal;
  readonly personalization_storage: GcmSignal;
  readonly security_storage: GcmSignal;
}

export type GcmUpdate = Partial<GcmDefaults>;

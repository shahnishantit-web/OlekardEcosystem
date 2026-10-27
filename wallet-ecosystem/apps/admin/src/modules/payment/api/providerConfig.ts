import type {
  UpdateAccountPayload,
  LifecycleTransitionPayload,
  UpdateModuleConfigPayload,
  HighRiskUpdateResponse,
  ConfigDraftStatus,
  PaymentProviderAccountDetail,
  ConfigDraft,
  ModuleConfig,
  PaginatedResponse,
} from '@/lib/api/payment';

// Re-export for consumers that need these types via providerConfig module
export type {
  UpdateAccountPayload,
  LifecycleTransitionPayload,
  UpdateModuleConfigPayload,
  HighRiskUpdateResponse,
  ConfigDraftStatus,
  PaymentProviderAccountDetail,
  ConfigDraft,
  ModuleConfig,
  PaginatedResponse,
};

export function isHighRiskUpdateResponse(
  res: unknown,
): res is HighRiskUpdateResponse {
  return (
    typeof res === 'object' &&
    res !== null &&
    'requires_approval' in res &&
    (res as HighRiskUpdateResponse).requires_approval === true
  );
}

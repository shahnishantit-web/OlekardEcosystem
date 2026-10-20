'use client';

// T068: Non-dismissible banner for mock/dev providers — never shown for production providers

interface Props {
  providerCode?: string;
}

export function MockBannerAlert({ providerCode }: Props) {
  return (
    <div
      role="alert"
      aria-live="assertive"
      className="rounded border border-red-400 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 flex items-center gap-2"
    >
      <span aria-hidden="true">⛔</span>
      <span>
        DEVELOPMENT MOCK — NOT FOR PRODUCTION
        {providerCode ? ` (${providerCode})` : ''}
        . This adapter is permanently blocked in production environments.
      </span>
    </div>
  );
}

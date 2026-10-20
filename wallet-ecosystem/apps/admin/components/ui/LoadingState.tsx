interface LoadingStateProps {
  label?: string;
  size?: 'sm' | 'md' | 'lg';
  /** Fill the nearest scrolling container; use for full-page section loads. */
  fullHeight?: boolean;
}

const SIZES = {
  sm: { spinner: 'w-5 h-5 border-2', gap: 'gap-2', text: 'text-xs' },
  md: { spinner: 'w-8 h-8 border-2', gap: 'gap-3', text: 'text-sm' },
  lg: { spinner: 'w-12 h-12 border-[3px]', gap: 'gap-4', text: 'text-sm' },
};

export function LoadingState({
  label = 'Loading…',
  size = 'md',
  fullHeight = false,
}: LoadingStateProps) {
  const cls = SIZES[size];
  return (
    <div
      role="status"
      aria-live="polite"
      aria-label={label}
      className={`flex flex-col items-center justify-center ${fullHeight ? 'min-h-[360px]' : 'py-12'}`}
    >
      {/* Spinner */}
      <div
        className={`${cls.spinner} rounded-full animate-spin`}
        style={{ borderColor: '#e8ebf1', borderTopColor: '#596fb6' }}
        aria-hidden="true"
      />
      {/* Visible label (kept small and muted) */}
      <p className={`mt-3 ${cls.text} text-text-secondary ${cls.gap}`} aria-hidden="true">
        {label}
      </p>
      {/* Screen-reader only backup */}
      <span className="sr-only">{label}</span>
    </div>
  );
}

interface LoadingProps {
  label?: string;
}

export function Loading({ label = 'Loading' }: LoadingProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-label={label}
      className="flex items-center justify-center p-8"
    >
      <div
        className="h-6 w-6 rounded-full border-2 border-neutral-200 border-t-brand-primary animate-spin"
        aria-hidden="true"
      />
      <span className="sr-only">{label}</span>
    </div>
  );
}

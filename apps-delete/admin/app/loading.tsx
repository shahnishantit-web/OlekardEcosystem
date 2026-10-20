export default function Loading() {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-label="Loading"
      className="flex min-h-screen items-center justify-center"
    >
      <div
        className="h-8 w-8 rounded-full border-2 border-neutral-200 border-t-brand-primary animate-spin"
        aria-hidden="true"
      />
    </div>
  );
}

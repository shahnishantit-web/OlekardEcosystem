export default function GlobalLoading() {
  return (
    <div aria-live="polite" role="status" aria-label="Loading...">
      <span className="sr-only">Loading...</span>
    </div>
  );
}

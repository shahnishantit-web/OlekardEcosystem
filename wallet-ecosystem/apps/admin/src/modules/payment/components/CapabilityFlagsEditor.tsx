'use client';

// T069: Capability flags checkbox grid for ProviderCapabilityEnum values.
// When the account is enabled, all checkboxes become read-only.

const ALL_CAPABILITIES = [
  { key: 'initiate_payment',    label: 'Initiate Payment' },
  { key: 'query_payment',       label: 'Query Payment Status' },
  { key: 'verify_callback',     label: 'Verify Callback' },
  { key: 'full_refund',         label: 'Full Refund' },
  { key: 'partial_refund',      label: 'Partial Refund' },
  { key: 'cancel_payment',      label: 'Cancel Payment' },
  { key: 'authorise_capture',   label: 'Authorise & Capture' },
  { key: 'payout',              label: 'Payout' },
] as const;

interface Props {
  value: string[];
  onChange: (flags: string[]) => void;
  disabled?: boolean;
}

export function CapabilityFlagsEditor({ value, onChange, disabled = false }: Props) {
  function toggle(key: string) {
    if (disabled) return;
    if (value.includes(key)) {
      onChange(value.filter((f) => f !== key));
    } else {
      onChange([...value, key]);
    }
  }

  return (
    <fieldset className="border border-border rounded p-4">
      <legend className="text-sm font-medium text-text-primary px-1">Capability Flags</legend>
      {disabled && (
        <p className="text-xs text-amber-700 mb-3">
          Capability flags are read-only while the account is enabled.
          Disable the account to edit flags.
        </p>
      )}
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {ALL_CAPABILITIES.map(({ key, label }) => (
          <label
            key={key}
            className={`flex items-center gap-2 text-sm cursor-pointer select-none ${
              disabled ? 'opacity-50 cursor-not-allowed' : ''
            }`}
          >
            <input
              type="checkbox"
              checked={value.includes(key)}
              onChange={() => toggle(key)}
              disabled={disabled}
              aria-label={label}
              className="rounded border-border text-ok-primary"
            />
            {label}
          </label>
        ))}
      </div>
    </fieldset>
  );
}

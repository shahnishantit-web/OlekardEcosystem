'use client';

const HIGH_RISK_FIELDS = ['merchant_account_reference', 'callback_secret_reference', 'settlement_destination'];

interface DiffEntry {
  current: unknown;
  proposed: unknown;
}

interface Props {
  diff: Record<string, DiffEntry>;
}

function formatValue(value: unknown): string {
  if (Array.isArray(value)) return value.join(', ');
  if (value === null || value === undefined) return '—';
  return String(value);
}

export function ConfigDiffPreview({ diff }: Props) {
  const entries = Object.entries(diff);
  if (entries.length === 0) return <p className="text-sm text-muted-foreground">No changes.</p>;

  return (
    <table className="w-full text-sm border-collapse">
      <thead>
        <tr className="border-b">
          <th className="text-left py-1 pr-4">Field</th>
          <th className="text-left py-1 pr-4">Current</th>
          <th className="text-left py-1 pr-4">Proposed</th>
        </tr>
      </thead>
      <tbody>
        {entries.map(([field, { current, proposed }]) => {
          const isSensitive = HIGH_RISK_FIELDS.includes(field);
          return (
            <tr key={field} className="border-b">
              <td className="py-1 pr-4 font-mono text-xs">{field}</td>
              <td className="py-1 pr-4">{isSensitive ? '••••' : formatValue(current)}</td>
              <td className="py-1 pr-4">{isSensitive ? '••••' : formatValue(proposed)}</td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

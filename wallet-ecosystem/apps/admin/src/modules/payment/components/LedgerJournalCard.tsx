import { Card } from '@/components/ui/Card';
import type { LedgerJournalRef } from '@/lib/api/payment';
import { CurrencyAmount } from './CurrencyAmount';

interface Props {
  journal: LedgerJournalRef;
}

export function LedgerJournalCard({ journal }: Props) {
  return (
    <Card semanticLabel={`Ledger journal ${journal.journal_id.slice(0, 8)}`}>
      <div className="mb-3 flex items-baseline justify-between gap-2">
        <p className="text-sm font-semibold text-text-primary">{journal.description}</p>
        <time className="text-xs text-text-secondary">
          {new Date(journal.posted_at).toLocaleString()}
        </time>
      </div>

      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-ok-border text-xs text-text-secondary">
            <th scope="col" className="pb-1 text-left font-medium">Account</th>
            <th scope="col" className="pb-1 text-left font-medium">Type</th>
            <th scope="col" className="pb-1 text-right font-medium">Amount</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-ok-border">
          {journal.lines.map((line, idx) => (
            // eslint-disable-next-line react/no-array-index-key
            <tr key={idx}>
              <td className="py-1.5 text-text-primary">{line.account_name}</td>
              <td className="py-1.5">
                <span
                  className={`text-xs font-medium ${
                    line.type === 'debit' ? 'text-ok-error-fg' : 'text-ok-success-fg'
                  }`}
                >
                  {line.type.toUpperCase()}
                </span>
              </td>
              <td className="py-1.5 text-right tabular-nums">
                <CurrencyAmount amountMinor={line.amount_minor} currency={line.currency_code} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {journal.audit_link && (
        <div className="mt-3 border-t border-ok-border pt-2">
          <a
            href={journal.audit_link}
            className="text-xs text-blue-600 hover:underline"
            target="_blank"
            rel="noreferrer"
          >
            View audit entry →
          </a>
        </div>
      )}
    </Card>
  );
}

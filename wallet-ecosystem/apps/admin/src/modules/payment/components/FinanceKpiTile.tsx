import { Card } from '@/components/ui/Card';

interface Props {
  label: string;
  value: string | number;
  currency?: string;
  trend?: 'up' | 'down' | 'neutral';
}

const trendIcon: Record<NonNullable<Props['trend']>, string> = {
  up:      '↑',
  down:    '↓',
  neutral: '→',
};

const trendColor: Record<NonNullable<Props['trend']>, string> = {
  up:      'text-ok-success-fg',
  down:    'text-ok-error-fg',
  neutral: 'text-text-secondary',
};

export function FinanceKpiTile({ label, value, currency, trend }: Props) {
  return (
    <Card semanticLabel={label}>
      <p className="text-xs font-medium uppercase tracking-wide text-text-secondary">{label}</p>
      <div className="mt-2 flex items-baseline gap-1.5">
        <span className="text-2xl font-semibold text-text-primary tabular-nums">
          {typeof value === 'number' ? value.toLocaleString() : value}
        </span>
        {currency && (
          <span className="text-sm font-medium text-text-secondary">{currency}</span>
        )}
        {trend && (
          <span
            aria-label={`trend ${trend}`}
            className={`ml-1 text-sm font-medium ${trendColor[trend]}`}
          >
            {trendIcon[trend]}
          </span>
        )}
      </div>
    </Card>
  );
}

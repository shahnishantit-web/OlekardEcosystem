import type { ReactNode } from 'react';

type EventType = 'intent' | 'attempt' | 'ledger' | 'fulfilment';

interface Props {
  type: EventType;
  title: string;
  timestamp: string;
  detail?: ReactNode;
  isLast?: boolean;
}

const typeColor: Record<EventType, string> = {
  intent:     'bg-blue-500',
  attempt:    'bg-indigo-500',
  ledger:     'bg-green-500',
  fulfilment: 'bg-purple-500',
};

export function TimelineEvent({ type, title, timestamp, detail, isLast = false }: Props) {
  return (
    <div className="relative flex gap-4">
      {/* Connector line */}
      {!isLast && (
        <div
          aria-hidden="true"
          className="absolute left-2.5 top-5 bottom-0 w-px bg-ok-border"
        />
      )}

      {/* Dot */}
      <div
        aria-hidden="true"
        className={`relative z-10 mt-1 h-5 w-5 flex-shrink-0 rounded-full ${typeColor[type]} shadow-sm`}
      />

      <div className="min-w-0 flex-1 pb-6">
        <div className="flex items-baseline justify-between gap-2">
          <p className="text-sm font-semibold text-text-primary">{title}</p>
          <time className="flex-shrink-0 text-xs text-text-secondary">
            {new Date(timestamp).toLocaleString()}
          </time>
        </div>
        {detail && <div className="mt-2">{detail}</div>}
      </div>
    </div>
  );
}

'use client';

import { useState } from 'react';
import { ConfigDiffPreview } from './ConfigDiffPreview';
import type { ConfigDraft } from '@/lib/api/payment';

interface Props {
  drafts: ConfigDraft[];
}

export function VersionHistoryTimeline({ drafts }: Props) {
  const [expandedId, setExpandedId] = useState<string | null>(null);

  const sorted = [...drafts].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());

  if (sorted.length === 0) {
    return <p className="text-muted-foreground text-sm">No configuration changes recorded.</p>;
  }

  return (
    <div className="space-y-3">
      {sorted.map((draft) => (
        <div key={draft.id} className="border-l-2 border-muted pl-4 pb-3">
          <div className="flex items-center gap-2 mb-1">
            <span className={`text-xs font-medium px-1.5 py-0.5 rounded ${
              draft.status === 'approved' ? 'bg-green-100 text-green-800' :
              draft.status === 'rejected' ? 'bg-red-100 text-red-800' :
              'bg-gray-100 text-gray-600'
            }`}>
              {draft.status}
            </span>
            <span className="text-xs text-muted-foreground">{new Date(draft.created_at).toLocaleString()}</span>
          </div>
          <p className="text-sm">
            <span className="font-medium">{draft.change_type}</span>
            {' by '}{draft.drafted_by_actor_id}
            {draft.reviewed_by_actor_id && (
              <> · {draft.status === 'approved' ? 'Approved' : 'Reviewed'} by {draft.reviewed_by_actor_id}</>
            )}
          </p>
          {draft.status === 'rejected' && draft.review_notes && (
            <p className="text-xs text-destructive mt-1">Denial reason: {draft.review_notes}</p>
          )}
          <button
            onClick={() => setExpandedId(expandedId === draft.id ? null : draft.id)}
            className="text-xs underline mt-1"
            aria-label={expandedId === draft.id ? 'Collapse change details' : 'Expand change details'}
          >
            {expandedId === draft.id ? 'Hide changes' : 'View changes'}
          </button>
          {expandedId === draft.id && (
            <div className="mt-2">
              <ConfigDiffPreview diff={
                Object.fromEntries(
                  Object.entries(draft.change_payload as Record<string, unknown>).map(([k, v]) => [k, { current: null, proposed: v }])
                )
              } />
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

import { render, screen } from '@testing-library/react';
import { ApprovalQueueTable } from '../ApprovalQueueTable';
import type { ConfigDraft } from '@/lib/api/payment';

const mockDraft: ConfigDraft = {
  id: 'draft-1',
  provider_id: 'prov-1',
  change_type: 'high_risk_update',
  change_payload: {},
  status: 'pending_approval',
  drafted_by_actor_id: 'user-A',
  reviewed_by_actor_id: null,
  reviewed_at: null,
  review_notes: null,
  account_config_version_at_draft_time: 1,
  created_at: new Date().toISOString(),
};

describe('ApprovalQueueTable', () => {
  it('shows empty state when no drafts', () => {
    render(<ApprovalQueueTable drafts={[]} currentUserId="me" onApprove={jest.fn()} onDeny={jest.fn()} />);
    expect(screen.getByText('No changes awaiting approval.')).toBeTruthy();
  });

  it('disables Approve when draft is by current user', () => {
    render(<ApprovalQueueTable drafts={[mockDraft]} currentUserId="user-A" onApprove={jest.fn()} onDeny={jest.fn()} />);
    const approveBtn = screen.getByRole('button', { name: /approve/i });
    expect(approveBtn).toBeDisabled();
  });

  it('shows Deny button', () => {
    render(<ApprovalQueueTable drafts={[mockDraft]} currentUserId="user-B" onApprove={jest.fn()} onDeny={jest.fn()} />);
    expect(screen.getByRole('button', { name: /deny/i })).toBeTruthy();
  });
});

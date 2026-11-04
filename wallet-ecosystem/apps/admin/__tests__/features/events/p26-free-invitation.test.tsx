import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EventAuthoringPage } from '@/src/modules/community/pages/event-authoring';
import { useAuth } from '@/lib/auth/context';

jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));

const mockUseAuth = useAuth as jest.MockedFunction<typeof useAuth>;

describe('P26 complimentary invitation admin flow', () => {
  beforeEach(() => {
    Object.defineProperty(globalThis, 'crypto', {
      configurable: true,
      value: { randomUUID: jest.fn(() => 'p26-test-idempotency-key') },
    });
  });

  it('issues an invitation, displays its secret, and revokes only that invitation', async () => {
    const user = userEvent.setup();
    const client = {
      post: jest.fn()
        .mockResolvedValueOnce({ data: {
          id: 'event-1', organisation_id: 'org-1', version: 1, title: 'Community event',
          status: 'draft', sales_state: 'paused', categories: [],
        } })
        .mockResolvedValueOnce({ data: { invitation_id: 'invite-1', token: 'opaque-secret-token' } }),
      get: jest.fn(),
      put: jest.fn(),
      delete: jest.fn().mockResolvedValue({ data: { invitation_id: 'invite-1', status: 'revoked' } }),
    };
    mockUseAuth.mockReturnValue({
      authenticatedClient: client as never,
      hasPermission: () => true,
    } as never);

    render(<EventAuthoringPage />);
    fireEvent.change(screen.getByLabelText('Organisation ID'), { target: { value: 'org-1' } });
    fireEvent.change(screen.getByLabelText('New event draft data'), {
      target: { value: JSON.stringify({ title: 'Community event', categories: [] }) },
    });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));

    await user.type(screen.getByLabelText('Free/complimentary category ID'), 'category-1');
    await user.type(screen.getByLabelText('Recipient customer actor ID'), 'customer-1');
    fireEvent.change(screen.getByLabelText('Expiry'), { target: { value: '2026-10-31T17:00' } });
    await user.click(screen.getByRole('button', { name: 'Create invitation' }));

    const secret = await screen.findByLabelText('New invitation token') as HTMLTextAreaElement;
    expect(secret.value).toBe('opaque-secret-token');
    expect(client.post.mock.calls[1]?.[2]).toEqual(expect.objectContaining({
      idempotencyKey: expect.any(String),
    }));

    await user.click(screen.getByRole('button', { name: 'Revoke this invitation' }));
    await waitFor(() => expect(client.delete).toHaveBeenCalledWith('/v1/events/invitations/invite-1', {
      idempotencyKey: 'p26-test-idempotency-key',
    }));
    expect(screen.queryByLabelText('New invitation token')).not.toBeInTheDocument();
    expect(screen.getByText('Invitation revoked. Previously issued tickets remain valid.')).toBeInTheDocument();
  });

  it('uses the existing authorised assignment endpoint to grant complimentary approvers', async () => {
    const user = userEvent.setup();
    const client = {
      post: jest.fn().mockResolvedValue({ data: {
        id: 'event-1', organisation_id: 'org-1', version: 1, title: 'Community event',
        status: 'draft', sales_state: 'paused', categories: [],
      } }),
      get: jest.fn(), put: jest.fn(), delete: jest.fn(),
    };
    mockUseAuth.mockReturnValue({
      authenticatedClient: client as never,
      hasPermission: (permission: string) => ['events.event.create', 'events.assignment.grant'].includes(permission),
      user: { id: 'assignment-admin', email: 'admin@example.invalid', name: 'Admin' },
    } as never);

    render(<EventAuthoringPage />);
    fireEvent.change(screen.getByLabelText('Organisation ID'), { target: { value: 'org-1' } });
    fireEvent.change(screen.getByLabelText('New event draft data'), {
      target: { value: JSON.stringify({ title: 'Community event', categories: [] }) },
    });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));
    fireEvent.change(screen.getByLabelText('Approver actor ID'), { target: { value: 'approver-1' } });
    fireEvent.change(screen.getByLabelText('Assignment reason'), { target: { value: 'approved_by_governance' } });
    const grantButton = screen.getByRole('button', { name: 'Grant organisation approver' });
    await waitFor(() => expect(grantButton).toBeEnabled());
    fireEvent.click(grantButton);

    expect(client.post).toHaveBeenLastCalledWith('/v1/events/organisations/org-1/assignments', {
      target_actor_id: 'approver-1', permission_slug: 'events.complimentary.approve', reason_code: 'approved_by_governance',
    }, { idempotencyKey: expect.any(String) });
    expect(await screen.findByText('Organisation-scoped approval assignment granted by the authorized assignment service.')).toBeInTheDocument();
  });
});

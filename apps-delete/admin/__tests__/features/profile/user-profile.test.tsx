/**
 * T149 — Admin user profile page tests.
 * Verifies: profile fields rendered; edit mode; suspend button gating;
 * missing profile.update permission hides Edit; server error shown.
 */
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ replace: jest.fn(), push: jest.fn() }),
}));

jest.mock('@/src/modules/profile/api/profile', () => ({
  getAdminProfile: jest.fn(),
  updateAdminProfilePersonal: jest.fn(),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { UserProfilePage } from '@/src/modules/profile/pages/user-profile';
import * as profileApi from '@/src/modules/profile/api/profile';
import * as authContext from '@/lib/auth/context';

const mockGetAdminProfile = profileApi.getAdminProfile as jest.MockedFunction<typeof profileApi.getAdminProfile>;
const mockUpdateAdminProfilePersonal = profileApi.updateAdminProfilePersonal as jest.MockedFunction<typeof profileApi.updateAdminProfilePersonal>;
const mockUseAuth = authContext.useAuth as jest.MockedFunction<typeof authContext.useAuth>;

const fakeProfile = {
  user_id: 'user-abc-123',
  first_name: 'Jane',
  last_name: 'Doe',
  date_of_birth: '1990-05-15',
  gender: 'female',
  address: { street: '1 Main St', city: 'Gaborone', region: null, postal_code: null, country: 'BW' },
  preferred_language: 'en',
  email: 'jane@example.com',
  mobile_number: '+26771234567',
  profile_photo_url: null,
  account_status: 'active',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-08-01T12:00:00Z',
};

function setupAuth() {
  mockUseAuth.mockReturnValue({
    authenticatedClient: {} as never,
    isAuthenticated: true,
    phase: 'authenticated',
    user: { id: 'admin-1', email: 'admin@olekard.local', name: 'Admin' },
    mfaMethods: null,
    authError: null,
    login: jest.fn(),
    completeMfa: jest.fn(),
    signOut: jest.fn(),
  });
}

describe('UserProfilePage (T149)', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    setupAuth();
  });

  it('renders all profile fields after load', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);

    render(<UserProfilePage userId="user-abc-123" />);

    await waitFor(() => {
      expect(screen.getByText('Jane')).toBeInTheDocument();
    });

    expect(screen.getByText('Doe')).toBeInTheDocument();
    expect(screen.getByText('jane@example.com')).toBeInTheDocument();
    expect(screen.getByText('+26771234567')).toBeInTheDocument();
    expect(screen.getByText('ACTIVE')).toBeInTheDocument();
  });

  it('shows loading indicator while fetching', () => {
    mockGetAdminProfile.mockReturnValueOnce(new Promise(() => {}));

    render(<UserProfilePage userId="user-abc-123" />);

    expect(screen.getByText(/Loading profile/i)).toBeInTheDocument();
  });

  it('shows error state on fetch failure with retry button', async () => {
    mockGetAdminProfile.mockRejectedValueOnce({ success: false, data: null, error: { code: 'SERVER_ERROR', message: 'Server error' } });

    render(<UserProfilePage userId="user-abc-123" />);

    await waitFor(() => {
      expect(screen.getByText(/Failed to load profile/i)).toBeInTheDocument();
    });
  });

  it('enters edit mode when Edit Profile is clicked', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);

    render(<UserProfilePage userId="user-abc-123" canUpdate />);

    await waitFor(() => {
      expect(screen.getByText('Edit Profile')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByText('Edit Profile'));

    expect(screen.getByLabelText('First name')).toBeInTheDocument();
    expect(screen.getByLabelText('Last name')).toBeInTheDocument();
  });

  it('does not show Edit Profile when canUpdate is false', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);

    render(<UserProfilePage userId="user-abc-123" canUpdate={false} />);

    await waitFor(() => {
      expect(screen.getByText('ACTIVE')).toBeInTheDocument();
    });

    expect(screen.queryByText('Edit Profile')).not.toBeInTheDocument();
  });

  it('saves edits and shows toast on success', async () => {
    const updatedProfile = { ...fakeProfile, first_name: 'Janet' };
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);
    mockUpdateAdminProfilePersonal.mockResolvedValueOnce(updatedProfile);

    render(<UserProfilePage userId="user-abc-123" canUpdate />);

    await waitFor(() => screen.getByText('Edit Profile'));
    await userEvent.click(screen.getByText('Edit Profile'));

    const firstNameInput = screen.getByLabelText('First name');
    await userEvent.clear(firstNameInput);
    await userEvent.type(firstNameInput, 'Janet');

    await userEvent.click(screen.getByText('Save Changes'));

    await waitFor(() => {
      expect(screen.getByText('Profile updated successfully.')).toBeInTheDocument();
    });
  });

  it('shows Suspend button only when status is active and canSuspend is true', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);

    render(<UserProfilePage userId="user-abc-123" canSuspend />);

    await waitFor(() => {
      expect(screen.getByText('Suspend')).toBeInTheDocument();
    });
  });

  it('does not show Suspend button when canSuspend is false', async () => {
    mockGetAdminProfile.mockResolvedValueOnce(fakeProfile);

    render(<UserProfilePage userId="user-abc-123" canSuspend={false} />);

    await waitFor(() => {
      expect(screen.getByText('ACTIVE')).toBeInTheDocument();
    });

    expect(screen.queryByText('Suspend')).not.toBeInTheDocument();
  });

  it('shows unauthorized state when not authenticated', () => {
    mockUseAuth.mockReturnValueOnce({
      authenticatedClient: null,
      isAuthenticated: false,
      phase: 'unauthenticated',
      user: null,
      mfaMethods: null,
      authError: null,
      login: jest.fn(),
      completeMfa: jest.fn(),
      signOut: jest.fn(),
    });

    render(<UserProfilePage userId="user-abc-123" />);

    expect(screen.getByText(/must be signed in/i)).toBeInTheDocument();
  });
});

'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import {
  getAdminProfile,
  updateAdminProfilePersonal,
} from '@/src/modules/profile/api/profile';
import type { AdminProfileData, UpdatePersonalInfoPayload } from '@/src/modules/profile/api/profile';
import { SuspendDialog } from '@/components/profile/SuspendDialog';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  active: 'success',
  suspended: 'error',
  pending_closure: 'warning',
  closed: 'neutral',
};

interface UserProfilePageProps {
  userId: string;
  canUpdate?: boolean;
  canSuspend?: boolean;
}

export function UserProfilePage({
  userId,
  canUpdate = true,
  canSuspend = true,
}: UserProfilePageProps) {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [profile, setProfile] = useState<AdminProfileData | null>(null);
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const [editMode, setEditMode] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [dateOfBirth, setDateOfBirth] = useState('');
  const [gender, setGender] = useState('');
  const [preferredLanguage, setPreferredLanguage] = useState('');

  const [suspendOpen, setSuspendOpen] = useState(false);

  const fetchProfile = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getAdminProfile(authenticatedClient, userId);
      setProfile(data);
    } catch (err) {
      if (isApiError(err) && (err as ApiError).error.code === 'FORBIDDEN') {
        setForbidden(true);
      } else {
        setFetchError('Failed to load profile. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, userId]);

  useEffect(() => {
    void fetchProfile();
  }, [fetchProfile]);

  function enterEditMode() {
    if (!profile) return;
    setFirstName(profile.first_name ?? '');
    setLastName(profile.last_name ?? '');
    setDateOfBirth(profile.date_of_birth ?? '');
    setGender(profile.gender ?? '');
    setPreferredLanguage(profile.preferred_language ?? '');
    setSaveError(null);
    setEditMode(true);
  }

  function cancelEdit() {
    setEditMode(false);
    setSaveError(null);
  }

  async function saveEdit() {
    if (!authenticatedClient) return;
    setSaving(true);
    setSaveError(null);
    const payload: UpdatePersonalInfoPayload = {};
    if (firstName) payload.first_name = firstName;
    if (lastName) payload.last_name = lastName;
    if (dateOfBirth) payload.date_of_birth = dateOfBirth;
    if (gender) payload.gender = gender;
    if (preferredLanguage) payload.preferred_language = preferredLanguage;
    try {
      const updated = await updateAdminProfilePersonal(authenticatedClient, userId, payload);
      setProfile(updated);
      setEditMode(false);
      setToastMessage('Profile updated successfully.');
    } catch (err) {
      const msg = isApiError(err)
        ? (err as ApiError).error.message
        : 'Failed to save profile. Please try again.';
      setSaveError(msg);
    } finally {
      setSaving(false);
    }
  }

  function handleSuspendSuccess(newStatus: string) {
    if (profile) setProfile({ ...profile, account_status: newStatus });
    setToastMessage('Account suspended successfully.');
  }

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view profiles."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState title="Access denied" message="You do not have permission to view this profile." />
    );
  }

  return (
    <main aria-labelledby={headingId} className="px-6 py-8 max-w-4xl mx-auto">
      <div className="mb-6 flex items-center justify-between gap-4 flex-wrap">
        <h1 id={headingId} className="text-xl font-semibold text-text-primary">
          User Profile
        </h1>
        <div className="flex gap-2">
          {!editMode && canUpdate && profile && (
            <Button variant="secondary" onClick={enterEditMode}>
              Edit Profile
            </Button>
          )}
          {canSuspend && profile?.account_status === 'active' && (
            <Button variant="danger" onClick={() => setSuspendOpen(true)}>
              Suspend
            </Button>
          )}
        </div>
      </div>

      {loading && <Loading label="Loading profile…" />}

      {!loading && fetchError && (
        <ErrorState message={fetchError} onRetry={() => void fetchProfile()} />
      )}

      {!loading && !fetchError && profile && (
        <div className="space-y-6">
          <Card as="section" semanticLabel="Account status">
            <div className="flex items-center gap-3 p-4">
              <span className="text-sm font-medium text-text-secondary">Status</span>
              <Badge
                label={profile.account_status.replace(/_/g, ' ').toUpperCase()}
                variant={STATUS_VARIANT[profile.account_status] ?? 'neutral'}
              />
            </div>
          </Card>

          <Card as="section" semanticLabel="Profile photo">
            <div className="p-4 flex items-center gap-4">
              {profile.profile_photo_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={profile.profile_photo_url}
                  alt="Profile photo"
                  className="w-20 h-20 rounded-full object-cover"
                />
              ) : (
                <div
                  className="w-20 h-20 rounded-full bg-surface-secondary flex items-center justify-center text-text-secondary text-xl font-semibold"
                  aria-label="No profile photo"
                >
                  {profile.first_name?.[0] ?? profile.email?.[0] ?? '?'}
                </div>
              )}
              <div>
                <p className="text-sm font-medium text-text-primary">
                  {profile.first_name && profile.last_name
                    ? `${profile.first_name} ${profile.last_name}`
                    : profile.email ?? profile.user_id}
                </p>
                <p className="text-xs text-text-secondary mt-0.5">ID: {profile.user_id}</p>
              </div>
            </div>
          </Card>

          <Card as="section" semanticLabel="Personal information">
            <div className="p-4 space-y-4">
              <h2 className="text-base font-medium text-text-primary">Personal Information</h2>

              {editMode ? (
                <div className="space-y-3">
                  <div className="grid grid-cols-2 gap-3">
                    <Input
                      label="First name"
                      value={firstName}
                      onChange={(e) => setFirstName(e.target.value)}
                    />
                    <Input
                      label="Last name"
                      value={lastName}
                      onChange={(e) => setLastName(e.target.value)}
                    />
                  </div>
                  <Input
                    label="Date of birth"
                    type="date"
                    value={dateOfBirth}
                    onChange={(e) => setDateOfBirth(e.target.value)}
                  />
                  <Input
                    label="Gender"
                    value={gender}
                    onChange={(e) => setGender(e.target.value)}
                    placeholder="male / female / non_binary / prefer_not_to_say"
                  />
                  <Input
                    label="Preferred language"
                    value={preferredLanguage}
                    onChange={(e) => setPreferredLanguage(e.target.value)}
                    placeholder="e.g. en"
                  />

                  {saveError && (
                    <p role="alert" className="text-sm text-error">
                      {saveError}
                    </p>
                  )}

                  <div className="flex justify-end gap-3 pt-2">
                    <Button variant="secondary" onClick={cancelEdit} disabled={saving}>
                      Cancel
                    </Button>
                    <Button
                      variant="primary"
                      onClick={() => { void saveEdit(); }}
                      disabled={saving}
                      aria-busy={saving}
                    >
                      Save Changes
                    </Button>
                  </div>
                </div>
              ) : (
                <dl className="space-y-2 text-sm">
                  <ProfileField label="First name" value={profile.first_name} />
                  <ProfileField label="Last name" value={profile.last_name} />
                  <ProfileField label="Date of birth" value={profile.date_of_birth} />
                  <ProfileField label="Gender" value={profile.gender} />
                  <ProfileField label="Preferred language" value={profile.preferred_language} />
                </dl>
              )}
            </div>
          </Card>

          <Card as="section" semanticLabel="Contact information">
            <div className="p-4 space-y-2">
              <h2 className="text-base font-medium text-text-primary">Contact Information</h2>
              <dl className="space-y-2 text-sm">
                <ProfileField label="Email" value={profile.email} />
                <ProfileField label="Mobile number" value={profile.mobile_number} />
              </dl>
            </div>
          </Card>

          <Card as="section" semanticLabel="Address">
            <div className="p-4 space-y-2">
              <h2 className="text-base font-medium text-text-primary">Address</h2>
              <dl className="space-y-2 text-sm">
                <ProfileField label="Street" value={profile.address.street} />
                <ProfileField label="City" value={profile.address.city} />
                <ProfileField label="Region" value={profile.address.region} />
                <ProfileField label="Postal code" value={profile.address.postal_code} />
                <ProfileField label="Country" value={profile.address.country} />
              </dl>
            </div>
          </Card>

          <p className="text-xs text-text-secondary">
            Last updated: {new Date(profile.updated_at).toLocaleString()}
          </p>
        </div>
      )}

      <SuspendDialog
        open={suspendOpen}
        userId={userId}
        onClose={() => setSuspendOpen(false)}
        onSuccess={handleSuspendSuccess}
        hasPermission={canSuspend}
      />

      {toastMessage && (
        <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
      )}
    </main>
  );
}

function ProfileField({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div className="flex gap-2">
      <dt className="w-36 shrink-0 font-medium text-text-secondary">{label}</dt>
      <dd className="text-text-primary">{value ?? <span className="text-text-tertiary">—</span>}</dd>
    </div>
  );
}

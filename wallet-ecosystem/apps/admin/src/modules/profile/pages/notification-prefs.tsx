'use client';

import { useCallback, useEffect, useState } from 'react';
import { Card } from '@/components/ui/Card';
import { Loading } from '@/components/ui/Loading';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import {
  getNotificationPrefs,
  updateNotificationPrefs,
} from '@/src/modules/profile/api/notification-prefs';
import type { NotificationPreferences } from '@/src/modules/profile/api/notification-prefs';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface CategoryToggleProps {
  label: string;
  description: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (checked: boolean) => void;
}

function CategoryToggle({
  label,
  description,
  checked,
  disabled = false,
  onChange,
}: CategoryToggleProps) {
  return (
    <div className="flex items-start justify-between py-3 border-b border-neutral-700 last:border-0">
      <div className="flex-1 pr-4">
        <p className="text-sm font-medium text-text-primary">{label}</p>
        <p className="text-xs text-text-secondary mt-0.5">{description}</p>
        {disabled && (
          <p className="text-xs text-ok-accent mt-0.5">Required — cannot be disabled</p>
        )}
      </div>
      <label className="relative inline-flex items-center cursor-pointer">
        <input
          type="checkbox"
          className="sr-only peer"
          checked={checked}
          disabled={disabled}
          onChange={(e) => onChange(e.target.checked)}
          aria-label={label}
        />
        <div
          className={[
            'w-10 h-6 rounded-full transition-colors',
            'peer-focus-visible:ring-2 peer-focus-visible:ring-ok-accent',
            checked ? 'bg-ok-accent' : 'bg-neutral-600',
            disabled ? 'opacity-50 cursor-not-allowed' : '',
          ].join(' ')}
        >
          <div
            className={[
              'absolute top-1 left-1 w-4 h-4 bg-white rounded-full transition-transform',
              checked ? 'translate-x-4' : 'translate-x-0',
            ].join(' ')}
          />
        </div>
      </label>
    </div>
  );
}

const MANDATORY_CATEGORIES = ['financial', 'security'];

const CATEGORY_META: Record<string, { label: string; description: string }> = {
  financial: {
    label: 'Financial alerts',
    description: 'Transaction confirmations, balance changes, and payment notifications.',
  },
  security: {
    label: 'Security alerts',
    description: 'Login alerts, password changes, and suspicious activity warnings.',
  },
  operational: {
    label: 'Operational updates',
    description: 'System maintenance, service updates, and feature announcements.',
  },
  marketing: {
    label: 'Marketing communications',
    description: 'Product updates, promotions, and OleKard news.',
  },
};

export function NotificationPrefsPage() {
  const { authenticatedClient } = useAuth();

  const [prefs, setPrefs] = useState<NotificationPreferences | null>(null);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  const loadPrefs = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await getNotificationPrefs(authenticatedClient);
      setPrefs(data);
    } catch {
      setFetchError('Failed to load notification preferences.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void loadPrefs();
  }, [loadPrefs]);

  async function handleToggle(category: string, checked: boolean) {
    if (!authenticatedClient || !prefs) return;
    setSaveError(null);
    setSaveSuccess(false);

    const next = checked
      ? [...new Set([...prefs.email_enabled_categories, category])]
      : prefs.email_enabled_categories.filter((c) => c !== category);

    // Optimistic update
    setPrefs((p) => p ? { ...p, email_enabled_categories: next } : p);

    try {
      const updated = await updateNotificationPrefs(authenticatedClient, {
        email_enabled_categories: next,
      });
      setPrefs(updated);
      setSaveSuccess(true);
    } catch (err) {
      // Rollback
      setPrefs((p) =>
        p ? { ...p, email_enabled_categories: prefs.email_enabled_categories } : p,
      );
      setSaveError(
        isApiError(err) ? (err as ApiError).error.message : 'Failed to save preferences.',
      );
    }
  }

  const allCategories = Object.keys(CATEGORY_META);

  return (
    <PageContainer>
      <PageHeader
        title="Notification Preferences"
        description="Choose which email notifications you receive."
      />

      {saveSuccess && (
        <Toast
          message="Preferences saved."
          onDismiss={() => setSaveSuccess(false)}
        />
      )}

      <Card>
        {loading ? (
          <Loading label="Loading preferences…" />
        ) : fetchError ? (
          <p className="text-sm text-red-600" role="alert">{fetchError}</p>
        ) : prefs ? (
          <div>
            <h2 className="text-base font-semibold text-text-primary mb-2">
              Email Notifications
            </h2>
            <p className="text-sm text-text-secondary mb-4">
              Financial and security alerts are required and cannot be disabled.
            </p>
            {saveError && (
              <p className="text-sm text-red-600 mb-3" role="alert">{saveError}</p>
            )}
            {allCategories.map((cat) => {
              const meta = CATEGORY_META[cat];
              if (!meta) return null;
              const isMandatory = MANDATORY_CATEGORIES.includes(cat);
              const isEnabled = isMandatory || prefs.email_enabled_categories.includes(cat);
              return (
                <CategoryToggle
                  key={cat}
                  label={meta.label}
                  description={meta.description}
                  checked={isEnabled}
                  disabled={isMandatory}
                  onChange={(checked) => { void handleToggle(cat, checked); }}
                />
              );
            })}
          </div>
        ) : null}
      </Card>
    </PageContainer>
  );
}

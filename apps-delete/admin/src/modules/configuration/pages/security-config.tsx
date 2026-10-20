'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import {
  listSecurityConfig,
  updateSecurityConfigKey,
  CONFIG_SECTIONS,
} from '@/src/modules/configuration/api/security-config';
import type { ConfigEntry } from '@/src/modules/configuration/api/security-config';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

interface EditState {
  key: string;
  value: string;
  version: string;
  reason: string;
  error: string | null;
  saving: boolean;
}

function formatSensitiveValue(entry: ConfigEntry): string {
  return entry.is_sensitive ? '[REDACTED]' : entry.value;
}

function typeHint(entry: ConfigEntry): string {
  if (entry.data_type === 'integer') {
    const parts = [];
    if (entry.min !== undefined) parts.push(`min: ${entry.min}`);
    if (entry.max !== undefined) parts.push(`max: ${entry.max}`);
    return parts.length ? `Integer (${parts.join(', ')})` : 'Integer';
  }
  if (entry.data_type === 'boolean') return 'true or false';
  if (entry.data_type === 'decimal') return 'Decimal number';
  if (entry.allowed_values) return `One of: ${entry.allowed_values.join(', ')}`;
  return 'Text';
}

interface ConfigRowProps {
  entry: ConfigEntry;
  editState: EditState | null;
  onEdit: (entry: ConfigEntry) => void;
  onSave: () => void;
  onCancel: () => void;
  onChange: (field: 'value' | 'reason', value: string) => void;
}

function ConfigRow({ entry, editState, onEdit, onSave, onCancel, onChange }: ConfigRowProps) {
  const isEditing = editState?.key === entry.key;

  return (
    <tr className="border-b border-neutral-100 hover:bg-bg-secondary">
      <td className="px-4 py-3">
        <code className="text-xs font-mono text-text-primary bg-neutral-100 px-1.5 py-0.5 rounded">
          {entry.key}
        </code>
      </td>
      <td className="px-4 py-3 text-sm text-text-secondary max-w-xs">
        {entry.description}
      </td>
      <td className="px-4 py-3">
        {isEditing ? (
          <div className="flex flex-col gap-2 min-w-[200px]">
            <Input
              label="New value"
              type={entry.data_type === 'integer' ? 'number' : 'text'}
              value={editState.value}
              onChange={(e) => onChange('value', e.target.value)}
              hint={typeHint(entry)}
              error={editState.error ?? undefined}
              required
            />
            <Input
              label="Change note (optional)"
              type="text"
              value={editState.reason}
              onChange={(e) => onChange('reason', e.target.value)}
            />
            <div className="flex gap-2">
              <Button
                onClick={onSave}
                loading={editState.saving}
                className="text-xs"
                style={{ minHeight: '32px' }}
              >
                Save
              </Button>
              <Button
                variant="secondary"
                onClick={onCancel}
                disabled={editState.saving}
                className="text-xs"
                style={{ minHeight: '32px' }}
              >
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <span className={`text-sm ${entry.is_sensitive ? 'text-text-secondary italic' : 'text-text-primary font-mono'}`}>
            {formatSensitiveValue(entry)}
          </span>
        )}
      </td>
      <td className="px-4 py-3">
        <span className="text-xs text-text-secondary font-mono">{entry.default_value}</span>
      </td>
      <td className="px-4 py-3">
        <Badge
          label={entry.data_type}
          variant="info"
        />
      </td>
      <td className="px-4 py-3 text-xs text-text-secondary whitespace-nowrap">
        {new Date(entry.last_updated_at).toLocaleDateString()}<br />
        <span className="text-text-secondary/70">{entry.last_updated_by}</span>
      </td>
      <td className="px-4 py-3">
        {!isEditing && !entry.is_sensitive && (
          <Button
            variant="ghost"
            className="text-xs"
            style={{ minHeight: '32px' }}
            onClick={() => onEdit(entry)}
            aria-label={`Edit ${entry.key}`}
          >
            Edit
          </Button>
        )}
      </td>
    </tr>
  );
}

interface SectionTableProps {
  title: string;
  entries: ConfigEntry[];
  editState: EditState | null;
  onEdit: (entry: ConfigEntry) => void;
  onSave: () => void;
  onCancel: () => void;
  onChange: (field: 'value' | 'reason', value: string) => void;
}

function SectionTable({ title, entries, editState, onEdit, onSave, onCancel, onChange }: SectionTableProps) {
  const headingId = useId();

  if (entries.length === 0) return null;

  return (
    <section aria-labelledby={headingId} className="mb-8">
      <h2
        id={headingId}
        className="text-base font-semibold text-text-primary mb-3 px-4"
      >
        {title}
      </h2>
      <div className="overflow-x-auto border border-neutral-200 rounded-md">
        <table className="w-full text-sm text-left border-collapse">
          <caption className="sr-only">{title} configuration settings</caption>
          <thead>
            <tr className="border-b border-neutral-200 bg-bg-secondary">
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Key</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Description</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Current value</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Default</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Type</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">Last updated</th>
              <th scope="col" className="px-4 py-3 font-medium text-text-secondary text-xs">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {entries.map((entry) => (
              <ConfigRow
                key={entry.key}
                entry={entry}
                editState={editState?.key === entry.key ? editState : null}
                onEdit={onEdit}
                onSave={onSave}
                onCancel={onCancel}
                onChange={onChange}
              />
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

export function AdminSecurityConfigPage() {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [configMap, setConfigMap] = useState<Map<string, ConfigEntry>>(new Map());
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [editState, setEditState] = useState<EditState | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const fetchConfig = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setFetchError(null);
    try {
      const data = await listSecurityConfig(authenticatedClient);
      const map = new Map(data.configuration.map((e) => [e.key, e]));
      setConfigMap(map);
    } catch (err) {
      if (isApiError(err) && ((err as ApiError).error.code === 'IDENTITY_022' || (err as ApiError).error.code === 'IDENTITY_010')) {
        setForbidden(true);
      } else {
        setFetchError('Failed to load configuration. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    void fetchConfig();
  }, [fetchConfig]);

  function handleEdit(entry: ConfigEntry) {
    setEditState({
      key: entry.key,
      value: entry.value,
      version: entry.last_updated_at,
      reason: '',
      error: null,
      saving: false,
    });
  }

  function handleCancel() {
    setEditState(null);
  }

  function handleChange(field: 'value' | 'reason', value: string) {
    setEditState((prev) => (prev ? { ...prev, [field]: value, error: null } : prev));
  }

  async function handleSave() {
    if (!editState || !authenticatedClient) return;
    setEditState((prev) => (prev ? { ...prev, saving: true, error: null } : prev));

    try {
      const result = await updateSecurityConfigKey(
        authenticatedClient,
        editState.key,
        editState.value,
        editState.version,
        editState.reason || undefined,
      );

      setConfigMap((prev) => {
        const next = new Map(prev);
        const existing = next.get(result.key);
        if (existing) {
          next.set(result.key, {
            ...existing,
            value: result.new_value,
            last_updated_at: result.updated_at,
            last_updated_by: result.updated_by,
          });
        }
        return next;
      });

      setEditState(null);
      setToastMessage(`${result.key} updated successfully.`);
    } catch (err) {
      let errorMsg = 'Failed to save. Please try again.';
      if (isApiError(err)) {
        const code = (err as ApiError).error.code;
        if (code === 'IDENTITY_016') {
          errorMsg = 'Another administrator updated this value. Re-fetch the page and try again.';
        } else if (code === 'IDENTITY_001') {
          errorMsg = (err as ApiError).error.message;
        } else {
          errorMsg = (err as ApiError).error.message;
        }
      }
      setEditState((prev) => (prev ? { ...prev, saving: false, error: errorMsg } : prev));
    }
  }

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view configuration."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState
        title="Access denied"
        message="You do not have permission to view or edit security configuration."
      />
    );
  }

  const allEntries = Array.from(configMap.values());

  return (
    <main aria-labelledby={headingId} className="px-6 py-8 max-w-6xl mx-auto">
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1
            id={headingId}
            className="text-xl font-semibold text-text-primary"
          >
            Security Configuration
          </h1>
          <p className="mt-1 text-sm text-text-secondary">
            {allEntries.length > 0 && `${allEntries.length} configuration keys`}
          </p>
        </div>
        <Button
          variant="secondary"
          onClick={() => void fetchConfig()}
          loading={loading}
        >
          Refresh
        </Button>
      </div>

      {loading && <Loading label="Loading configuration…" />}

      {!loading && fetchError && (
        <ErrorState
          message={fetchError}
          onRetry={() => void fetchConfig()}
        />
      )}

      {!loading && !fetchError && allEntries.length === 0 && (
        <Empty title="No configuration found" description="The configuration registry is empty." />
      )}

      {!loading && !fetchError && allEntries.length > 0 && (
        <>
          {Object.entries(CONFIG_SECTIONS).map(([sectionTitle, keys]) => {
            const sectionEntries = keys.flatMap((k) => {
              const entry = configMap.get(k);
              return entry ? [entry] : [];
            });
            return (
              <SectionTable
                key={sectionTitle}
                title={sectionTitle}
                entries={sectionEntries}
                editState={editState}
                onEdit={handleEdit}
                onSave={() => { void handleSave(); }}
                onCancel={handleCancel}
                onChange={handleChange}
              />
            );
          })}
        </>
      )}

      {toastMessage && (
        <Toast
          message={toastMessage}
          onDismiss={() => setToastMessage(null)}
        />
      )}
    </main>
  );
}

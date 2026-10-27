'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { useAuth } from '@/lib/auth/context';

interface TicketTypeInput {
  name: string;
  price_minor: number;
  capacity: number;
}

export function CampaignCreatePage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [serviceType, setServiceType] = useState<'funeral' | 'events' | 'raffle'>('events');
  const [form, setForm] = useState({
    organisation_id: '',
    title: '',
    description: '',
    contribution_amount_minor: 2000,
    currency: 'BWP',
    metadata: {} as Record<string, string>,
    starts_at: '',
    ends_at: '',
    capacity: 0,
  });
  const [ticketTypes, setTicketTypes] = useState<TicketTypeInput[]>([
    { name: 'General', price_minor: 10000, capacity: 0 },
  ]);
  const [saving, setSaving]   = useState(false);
  const [error, setError]     = useState<string | null>(null);

  function setF(key: string, value: unknown) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function setMeta(key: string, value: string) {
    setForm((f) => ({ ...f, metadata: { ...f.metadata, [key]: value } }));
  }

  function addTicketType() {
    setTicketTypes((tt) => [...tt, { name: '', price_minor: 0, capacity: 0 }]);
  }

  function updateTicketType(index: number, field: keyof TicketTypeInput, value: string | number) {
    setTicketTypes((tt) => tt.map((t, i) => i === index ? { ...t, [field]: value } : t));
  }

  function removeTicketType(index: number) {
    setTicketTypes((tt) => tt.filter((_, i) => i !== index));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (serviceType === 'funeral') {
      setError('Funeral Assistance creation is unavailable while P24 verification is in progress.');
      return;
    }
    if (!authenticatedClient) return;
    setSaving(true);
    setError(null);

    try {
      const payload: Record<string, unknown> = {
        ...form,
        service_type: serviceType,
        contribution_amount_minor: undefined,
        capacity: serviceType === 'events' ? form.capacity || undefined : undefined,
        starts_at: form.starts_at || undefined,
        ends_at: form.ends_at || undefined,
        ticket_types: serviceType === 'events' ? ticketTypes.map((t, i) => ({ ...t, sort_order: i })) : undefined,
      };

      const res = await authenticatedClient.post<{ id: string }>('/v1/community/admin/campaigns', payload);
      router.push(`/community/campaigns/${res.data.id}`);
    } catch {
      setError('Failed to create campaign. Check all required fields.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <PageContainer>
      <PageHeader
        title={serviceType === 'funeral' ? 'New Funeral Collection'
          : serviceType === 'events' ? 'Create Event'
          : 'New Campaign'}
      />

      <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4 max-w-2xl">
        {/* Service type */}
        <Card>
          <h2 className="text-sm font-semibold text-text-primary mb-3">Service Type</h2>
          <div className="flex gap-3">
            {(['funeral', 'events', 'raffle'] as const).map((t) => (
              <label key={t} className="flex items-center gap-2 cursor-pointer text-sm">
                <input
                  type="radio"
                  name="service_type"
                  value={t}
                  disabled={t === 'funeral'}
                  checked={serviceType === t}
                  onChange={() => setServiceType(t)}
                />
                <span className="capitalize">{t === 'funeral' ? '🕊 Funeral Assistance (unavailable)' : t === 'events' ? '🎟 Events & Ticketing' : '🎰 Raffle'}</span>
              </label>
            ))}
          </div>
        </Card>

        {/* Basic details */}
        <Card>
          <h2 className="text-sm font-semibold text-text-primary mb-3">Campaign Details</h2>
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-text-primary mb-1">Organisation ID *</label>
              <input
                type="text"
                required
                value={form.organisation_id}
                onChange={(e) => setF('organisation_id', e.target.value)}
                placeholder="UUID of the organisation"
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-text-primary mb-1">
                {serviceType === 'funeral' ? 'Bereaved Household / Collection Title *' : 'Event Title *'}
              </label>
              <input
                type="text"
                required
                maxLength={255}
                value={form.title}
                onChange={(e) => setF('title', e.target.value)}
                placeholder={serviceType === 'funeral' ? 'e.g. Mokoena Family Assistance' : 'e.g. BTC Tech Summit 2026'}
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-text-primary mb-1">Description</label>
              <textarea
                rows={3}
                value={form.description}
                onChange={(e) => setF('description', e.target.value)}
                className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
              />
            </div>
          </div>
        </Card>

        {/* Funeral-specific */}
        {serviceType === 'funeral' && (
          <Card>
            <h2 className="text-sm font-semibold text-text-primary mb-3">Funeral Collection Settings</h2>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Contribution Amount (minor units)</label>
                <input
                  type="number"
                  min={1}
                  value={form.contribution_amount_minor}
                  onChange={(e) => setF('contribution_amount_minor', parseInt(e.target.value, 10))}
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
                <p className="text-xs text-text-secondary mt-1">P20 = 2000. Standard community contribution.</p>
              </div>
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Bereaved Household Name</label>
                <input
                  type="text"
                  value={form.metadata['bereaved_household'] ?? ''}
                  onChange={(e) => setMeta('bereaved_household', e.target.value)}
                  placeholder="e.g. Mokoena Family"
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Funeral Date</label>
                <input
                  type="date"
                  value={form.metadata['funeral_date'] ?? ''}
                  onChange={(e) => setMeta('funeral_date', e.target.value)}
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
              </div>
            </div>
          </Card>
        )}

        {/* Events-specific */}
        {serviceType === 'events' && (
          <Card>
            <h2 className="text-sm font-semibold text-text-primary mb-3">Event Settings</h2>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-sm font-medium text-text-primary mb-1">Event Start *</label>
                  <input
                    type="datetime-local"
                    value={form.starts_at}
                    onChange={(e) => setF('starts_at', e.target.value)}
                    className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-text-primary mb-1">Event End</label>
                  <input
                    type="datetime-local"
                    value={form.ends_at}
                    onChange={(e) => setF('ends_at', e.target.value)}
                    className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Venue</label>
                <input
                  type="text"
                  value={form.metadata['venue'] ?? ''}
                  onChange={(e) => setMeta('venue', e.target.value)}
                  placeholder="e.g. Gaborone International Conference Centre"
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Sponsor (optional)</label>
                <input
                  type="text"
                  value={form.metadata['sponsor'] ?? ''}
                  onChange={(e) => setMeta('sponsor', e.target.value)}
                  placeholder="e.g. BTC"
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-primary mb-1">Total Capacity</label>
                <input
                  type="number"
                  min={0}
                  value={form.capacity}
                  onChange={(e) => setF('capacity', parseInt(e.target.value, 10))}
                  className="w-full rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
                />
              </div>

              {/* Ticket types */}
              <div>
                <div className="flex justify-between items-center mb-2">
                  <label className="text-sm font-medium text-text-primary">Ticket Types</label>
                  <Button type="button" variant="secondary" size="sm" onClick={addTicketType}>+ Add Type</Button>
                </div>
                <div className="space-y-2">
                  {ticketTypes.map((tt, i) => (
                    <div key={i} className="flex gap-2 items-center">
                      <input
                        type="text"
                        placeholder="Name (e.g. VIP)"
                        value={tt.name}
                        onChange={(e) => updateTicketType(i, 'name', e.target.value)}
                        className="flex-1 rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary"
                      />
                      <input
                        type="number"
                        placeholder="Price (minor)"
                        min={0}
                        value={tt.price_minor}
                        onChange={(e) => updateTicketType(i, 'price_minor', parseInt(e.target.value, 10))}
                        className="w-32 rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary"
                      />
                      <input
                        type="number"
                        placeholder="Capacity (0=unlimited)"
                        min={0}
                        value={tt.capacity}
                        onChange={(e) => updateTicketType(i, 'capacity', parseInt(e.target.value, 10))}
                        className="w-36 rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary"
                      />
                      {ticketTypes.length > 1 && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => removeTicketType(i)}>✕</Button>
                      )}
                    </div>
                  ))}
                </div>
                <p className="text-xs text-text-secondary mt-1">Price in minor units (BWP 100 = 10000). Capacity 0 = unlimited.</p>
              </div>
            </div>
          </Card>
        )}

        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}

        <div className="flex gap-3">
          <Button type="submit" variant="primary" disabled={saving}>
            {saving ? 'Creating…' : 'Create Campaign'}
          </Button>
          <Button type="button" variant="ghost" onClick={() => router.back()} disabled={saving}>
            Cancel
          </Button>
        </div>
      </form>
    </PageContainer>
  );
}

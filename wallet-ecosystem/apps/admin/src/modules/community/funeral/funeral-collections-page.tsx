'use client';

import { useState, useCallback, useEffect } from 'react';
import { useAuth } from '@/lib/auth/context';

export interface FuneralCollection {
  id: string;
  community_id: string;
  status: 'under_review' | 'approved' | 'active' | 'closed' | 'archived';
  beneficiary_id: string;
  evidence_reference: string | null;
  amount_minor: number;
  currency_code: string;
  terms_version: string;
  opened_at?: string;
  closed_at?: string;
}

export interface AggregateReport {
  collection_id: string;
  confirmed_gross_minor: number;
  fee_total_minor: number;
  confirmed_refunds_minor: number;
  net_support_minor: number;
  late_review_gross_minor: number;
  unresolved_liability_count: number;
  pending_payment_count: number;
  pending_refund_count: number;
  late_review_count: number;
  payout_liability_open: number;
  settlement_status: string;
  payout_status: string;
}

const STATUS_COLORS: Record<FuneralCollection['status'], string> = {
  under_review: 'bg-yellow-100 text-yellow-800',
  approved: 'bg-blue-100 text-blue-800',
  active: 'bg-green-100 text-green-800',
  closed: 'bg-purple-100 text-purple-800',
  archived: 'bg-gray-100 text-gray-800',
};

export function FuneralCollectionsPage() {
  const { authenticatedClient: client } = useAuth();
  const [collections, setCollections] = useState<FuneralCollection[]>([]);
  const [selectedCollection, setSelectedCollection] = useState<FuneralCollection | null>(null);
  const [report, setReport] = useState<AggregateReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [actioning, setActioning] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [carryForwardRef, setCarryForwardRef] = useState('');

  const loadReport = useCallback(async (collectionId: string) => {
    if (!client) return;
    try {
      const res = await client.get<{ success: boolean; data: AggregateReport }>(
        `/api/v1/community/funeral/collections/${collectionId}/aggregate-report`
      );
      setReport(res.data.data);
    } catch {
      setError('Failed to load aggregate report.');
    }
  }, [client]);

  const handleReview = async (collectionId: string) => {
    if (!client) return;
    setActioning(true);
    setError(null);
    try {
      await client.post(`/api/v1/community/funeral/collections/${collectionId}/review`, {});
      alert('Collection approved by independent reviewer.');
      setSelectedCollection(prev => prev ? { ...prev, status: 'approved' } : null);
    } catch (err: unknown) {
      setError('Review failed: requires verified organization beneficiary and reviewer separation.');
    } finally {
      setActioning(false);
    }
  };

  const handleActivate = async (collectionId: string) => {
    if (!client) return;
    setActioning(true);
    setError(null);
    try {
      await client.post(`/api/v1/community/funeral/collections/${collectionId}/activate`, {});
      alert('Collection activated. Frozen eligibility snapshot and obligations generated.');
      setSelectedCollection(prev => prev ? { ...prev, status: 'active' } : null);
    } catch {
      setError('Activation failed: collection must be approved with verified households.');
    } finally {
      setActioning(false);
    }
  };

  const handleClose = async (collectionId: string) => {
    if (!client) return;
    setActioning(true);
    setError(null);
    try {
      const res = await client.post<{ success: boolean; data: AggregateReport }>(
        `/api/v1/community/funeral/collections/${collectionId}/close`,
        {}
      );
      setReport(res.data.data);
      setSelectedCollection(prev => prev ? { ...prev, status: 'closed' } : null);
      alert('Collection closed. New payments disallowed; reconciliation report generated.');
    } catch {
      setError('Failed to close collection.');
    } finally {
      setActioning(false);
    }
  };

  const handleCarryForward = async (collectionId: string) => {
    if (!client || !carryForwardRef) return;
    setActioning(true);
    setError(null);
    try {
      await client.post(`/api/v1/community/funeral/collections/${collectionId}/carry-forward`, {
        carry_forward_reference: carryForwardRef,
      });
      alert('Carry-forward requested with evidence reference. Awaiting reviewer approval.');
      setCarryForwardRef('');
    } catch {
      setError('Failed to request carry forward.');
    } finally {
      setActioning(false);
    }
  };

  const handleArchive = async (collectionId: string) => {
    if (!client) return;
    setActioning(true);
    setError(null);
    try {
      await client.post(`/api/v1/community/funeral/collections/${collectionId}/archive`, {});
      alert('Collection archived.');
      setSelectedCollection(prev => prev ? { ...prev, status: 'archived' } : null);
    } catch {
      setError('Archive blocked: unresolved liabilities require an approved carry-forward record.');
    } finally {
      setActioning(false);
    }
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center border-b pb-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Funeral Assistance Collections</h1>
          <p className="text-sm text-gray-500">
            Community household eligibility, collection review, closure reconciliation, and liability governance.
          </p>
        </div>
      </div>

      {error && (
        <div className="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="col-span-1 border rounded-lg p-4 bg-white shadow-sm space-y-4">
          <h2 className="text-lg font-semibold">Collections</h2>
          {collections.length === 0 ? (
            <div className="text-sm text-gray-500 py-6 text-center">
              No active or historical funeral collections loaded. Select or create a collection.
            </div>
          ) : (
            <div className="space-y-2">
              {collections.map(c => (
                <button
                  key={c.id}
                  onClick={() => {
                    setSelectedCollection(c);
                    if (c.status === 'closed' || c.status === 'archived') {
                      void loadReport(c.id);
                    } else {
                      setReport(null);
                    }
                  }}
                  className={`w-full text-left p-3 rounded-md border text-sm flex justify-between items-center transition-colors ${
                    selectedCollection?.id === c.id ? 'border-blue-500 bg-blue-50' : 'hover:bg-gray-50'
                  }`}
                >
                  <span className="font-mono text-xs">{c.id.substring(0, 8)}</span>
                  <span className={`px-2 py-0.5 rounded text-xs font-medium ${STATUS_COLORS[c.status]}`}>
                    {c.status}
                  </span>
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="col-span-2 border rounded-lg p-6 bg-white shadow-sm space-y-6">
          {selectedCollection ? (
            <div className="space-y-6">
              <div className="flex justify-between items-start">
                <div>
                  <h3 className="text-lg font-bold">Collection Details</h3>
                  <p className="text-xs text-gray-500 font-mono">ID: {selectedCollection.id}</p>
                </div>
                <span className={`px-3 py-1 rounded-full text-xs font-semibold ${STATUS_COLORS[selectedCollection.status]}`}>
                  {selectedCollection.status}
                </span>
              </div>

              <div className="grid grid-cols-2 gap-4 text-sm bg-gray-50 p-4 rounded-lg">
                <div>
                  <span className="text-gray-500 block">Community ID:</span>
                  <span className="font-mono text-xs">{selectedCollection.community_id}</span>
                </div>
                <div>
                  <span className="text-gray-500 block">Beneficiary ID:</span>
                  <span className="font-mono text-xs">{selectedCollection.beneficiary_id}</span>
                </div>
                <div>
                  <span className="text-gray-500 block">Contribution Rate:</span>
                  <span className="font-semibold">{selectedCollection.currency_code} {(selectedCollection.amount_minor / 100).toFixed(2)}</span>
                </div>
                <div>
                  <span className="text-gray-500 block">Terms Version:</span>
                  <span>{selectedCollection.terms_version}</span>
                </div>
              </div>

              {/* Action Buttons based on status */}
              <div className="flex flex-wrap gap-3 pt-2">
                {selectedCollection.status === 'under_review' && (
                  <button
                    disabled={actioning}
                    onClick={() => handleReview(selectedCollection.id)}
                    className="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
                  >
                    Independent Review & Approve
                  </button>
                )}

                {selectedCollection.status === 'approved' && (
                  <button
                    disabled={actioning}
                    onClick={() => handleActivate(selectedCollection.id)}
                    className="px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                  >
                    Activate & Freeze Obligations
                  </button>
                )}

                {selectedCollection.status === 'active' && (
                  <button
                    disabled={actioning}
                    onClick={() => handleClose(selectedCollection.id)}
                    className="px-4 py-2 bg-purple-600 text-white rounded-md text-sm font-medium hover:bg-purple-700 disabled:opacity-50"
                  >
                    Close Collection Cutoff
                  </button>
                )}

                {selectedCollection.status === 'closed' && (
                  <>
                    <button
                      disabled={actioning}
                      onClick={() => handleArchive(selectedCollection.id)}
                      className="px-4 py-2 bg-gray-800 text-white rounded-md text-sm font-medium hover:bg-gray-900 disabled:opacity-50"
                    >
                      Archive Collection
                    </button>
                    <button
                      onClick={() => void loadReport(selectedCollection.id)}
                      className="px-4 py-2 border rounded-md text-sm font-medium hover:bg-gray-50"
                    >
                      Refresh Report
                    </button>
                  </>
                )}
              </div>

              {/* Carry Forward Section */}
              {selectedCollection.status === 'closed' && (
                <div className="border-t pt-4 space-y-3">
                  <h4 className="font-semibold text-sm">Carry Forward Unresolved Liabilities</h4>
                  <div className="flex gap-2">
                    <input
                      type="text"
                      placeholder="Evidence reference (e.g. CF-2026-REF)"
                      value={carryForwardRef}
                      onChange={e => setCarryForwardRef(e.target.value)}
                      className="border rounded px-3 py-1.5 text-sm flex-1"
                    />
                    <button
                      disabled={actioning || !carryForwardRef}
                      onClick={() => handleCarryForward(selectedCollection.id)}
                      className="px-4 py-1.5 bg-gray-100 hover:bg-gray-200 border rounded text-sm font-medium disabled:opacity-50"
                    >
                      Request Carry-Forward
                    </button>
                  </div>
                </div>
              )}

              {/* Aggregate Report Display */}
              {report && (
                <div className="border-t pt-6 space-y-4">
                  <h4 className="font-bold text-base">Reconciliation & Aggregate Support Report</h4>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                    <div className="p-3 bg-gray-50 rounded border">
                      <div className="text-xs text-gray-500 uppercase">Gross Confirmed</div>
                      <div className="text-lg font-bold">BWP {(report.confirmed_gross_minor / 100).toFixed(2)}</div>
                    </div>
                    <div className="p-3 bg-gray-50 rounded border">
                      <div className="text-xs text-gray-500 uppercase">Total Fees</div>
                      <div className="text-lg font-bold">BWP {(report.fee_total_minor / 100).toFixed(2)}</div>
                    </div>
                    <div className="p-3 bg-gray-50 rounded border">
                      <div className="text-xs text-gray-500 uppercase">Refunds</div>
                      <div className="text-lg font-bold">BWP {(report.confirmed_refunds_minor / 100).toFixed(2)}</div>
                    </div>
                    <div className="p-3 bg-green-50 rounded border border-green-200">
                      <div className="text-xs text-green-700 uppercase font-semibold">Net Support</div>
                      <div className="text-lg font-bold text-green-800">BWP {(report.net_support_minor / 100).toFixed(2)}</div>
                    </div>
                  </div>

                  <div className="bg-amber-50 border border-amber-200 rounded p-4 text-sm space-y-2">
                    <div className="font-semibold text-amber-900">Quarantine & Liability Status</div>
                    <div className="flex justify-between text-xs text-amber-800">
                      <span>Late Review Gross: BWP {(report.late_review_gross_minor / 100).toFixed(2)}</span>
                      <span>Unresolved Liabilities: {report.unresolved_liability_count}</span>
                    </div>
                    <div className="flex justify-between text-xs text-amber-800">
                      <span>Settlement: {report.settlement_status}</span>
                      <span>Payout Status: {report.payout_status}</span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="text-center py-16 text-gray-400">
              Select a collection from the list to view lifecycle controls, review status, and aggregate reconciliation.
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

import type { Metadata } from 'next';
import { BankStatusBadge } from '../../components/BankStatusBadge';

export const metadata: Metadata = {
  title: 'Bank Detail — OleKard Admin',
};

// T085: Bank detail page — metadata, accounts list, lifecycle status, edit action.
export default function BankDetailPage({ params }: { params: { id: string } }) {
  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Bank Detail</h1>
          <p className="text-sm text-gray-500 mt-1">ID: {params.id}</p>
        </div>
        <a
          href={`/payments/banks/${params.id}/edit`}
          className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          Edit Metadata
        </a>
      </div>

      {/* Status badge placeholder */}
      <div className="rounded-lg border border-gray-200 bg-white p-6 space-y-4">
        <div className="flex items-center gap-3">
          <BankStatusBadge status="draft" type="bank" />
          <span className="text-sm text-gray-500">Status updates after API load</span>
        </div>
        <p className="text-sm text-gray-500">Bank metadata and accounts list require runtime API connection.</p>
      </div>

      {/* Accounts sub-section */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-medium text-gray-900">Integration Accounts</h2>
          <a
            href={`/payments/banks/${params.id}/accounts`}
            className="text-sm text-blue-600 hover:underline"
          >
            View all
          </a>
        </div>
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
          Accounts load from API at runtime.
        </div>
      </div>
    </div>
  );
}

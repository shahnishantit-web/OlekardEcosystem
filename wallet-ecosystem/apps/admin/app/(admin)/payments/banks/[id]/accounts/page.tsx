import type { Metadata } from 'next';

export const metadata: Metadata = {
  title: 'Integration Accounts — OleKard Admin',
};

// T086: Integration account list for a bank.
export default function AccountsPage({ params }: { params: { id: string } }) {
  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Integration Accounts</h1>
          <p className="text-sm text-gray-500 mt-1">Bank: {params.id}</p>
        </div>
        <a
          href={`/payments/banks/${params.id}/accounts/new`}
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          Add Account
        </a>
      </div>
      <div className="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500">
        Account list loads from API at runtime.
      </div>
    </div>
  );
}

import type { Metadata } from 'next';
import { BankList } from './BankList';

export const metadata: Metadata = {
  title: 'Bank Integrations — OleKard Admin',
};

// T083: Bank list page with status filter, paginated table, Add Bank button.
export default function BanksPage() {
  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Bank Integrations</h1>
          <p className="mt-1 text-sm text-gray-500">
            Configure and manage bank integration accounts and API operation mappings.
          </p>
        </div>
        <a
          href="/payments/banks/new"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
        >
          Add Bank
        </a>
      </div>

      <BankList />
    </div>
  );
}

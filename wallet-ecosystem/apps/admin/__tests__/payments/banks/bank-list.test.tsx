import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { BankList } from '@/app/(admin)/payments/banks/BankList';

const get = jest.fn();
const client = { get };
jest.mock('@/lib/auth/context', () => ({ useAuth: () => ({ authenticatedClient: client, hasPermission: () => true }) }));

beforeEach(() => get.mockReset());

it('loads dummy banks from the authenticated API, labels them and filters by status', async () => {
  get.mockResolvedValue({ data: [1, 2, 3].map(n => ({ id: `id-${n}`, code: `bank${n}`, display_name: `Bank${n}`,
    environment: 'mock', is_enabled: false, bank_lifecycle_status: 'draft', description: 'Development dummy bank — test only.' })), meta: { last_page: 1 } });
  render(<BankList />);
  expect(await screen.findByRole('link', { name: 'Bank1' })).toHaveAttribute('href', '/payments/banks/id-1');
  expect(screen.getByRole('link', { name: 'Bank2' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Bank3' })).toBeInTheDocument();
  expect(screen.getAllByText('Development mock · test only · Disabled')).toHaveLength(3);
  fireEvent.click(screen.getByRole('button', { name: 'draft' }));
  await waitFor(() => expect(get).toHaveBeenLastCalledWith('/api/v1/admin/payments/banks?page=1&per_page=25&status=draft', expect.any(Object)));
});

it('shows an API failure instead of fabricated bank records', async () => {
  get.mockRejectedValue(new Error('unavailable'));
  render(<BankList />);
  expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load banks');
  expect(screen.queryByRole('link', { name: 'Bank1' })).not.toBeInTheDocument();
});

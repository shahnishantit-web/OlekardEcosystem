import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';
import { OrganisationListPage } from '@/src/modules/community/pages/organisation-list';
import { ServiceEntitlementsPage } from '@/src/modules/community/pages/service-entitlements';
import { OrderListPage } from '@/src/modules/community/pages/order-list';

expect.extend(toHaveNoViolations);

jest.mock('@/lib/auth/context', () => ({
  useAuth: () => ({ authenticatedClient: null }),
}));

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn(), back: jest.fn() }),
  useParams: () => ({ id: 'test-id' }),
}));

describe('Community Admin Pages — WCAG 2.2 AA', () => {
  it('OrganisationListPage has no accessibility violations', async () => {
    const { container } = render(<OrganisationListPage />);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('ServiceEntitlementsPage has no accessibility violations', async () => {
    const { container } = render(<ServiceEntitlementsPage />);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('OrderListPage has no accessibility violations', async () => {
    const { container } = render(<OrderListPage />);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

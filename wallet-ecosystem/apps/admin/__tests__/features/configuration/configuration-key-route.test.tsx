import React from 'react';
import { render, screen } from '@testing-library/react';
import ConfigurationKeyDetailRoute from '@/app/(admin)/configuration/[domain]/[key]/page';

jest.mock('@/src/modules/configuration/pages/configuration-key-detail', () => ({
  ConfigurationKeyDetailPage: ({ configKey }: { configKey: string }) => (
    <div data-testid="configuration-key">{configKey}</div>
  ),
}));

describe('configuration detail route', () => {
  it.each([
    ['auth', 'auth.biometric.enabled'],
    ['device', 'device.max_devices_per_user'],
    ['ledger', 'ledger.float_account.BWP'],
    ['financial_policy', 'payment.daily_limit'],
  ])('preserves the full key for domain %s', (domain, key) => {
    render(<ConfigurationKeyDetailRoute params={{ domain, key }} />);
    expect(screen.getByTestId('configuration-key')).toHaveTextContent(key);
    expect(screen.getByTestId('configuration-key').textContent).toBe(key);
  });
});

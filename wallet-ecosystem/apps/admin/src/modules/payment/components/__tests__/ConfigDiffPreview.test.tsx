import { render, screen } from '@testing-library/react';
import { ConfigDiffPreview } from '../ConfigDiffPreview';

describe('ConfigDiffPreview', () => {
  it('renders empty state for empty diff', () => {
    render(<ConfigDiffPreview diff={{}} />);
    expect(screen.getByText('No changes.')).toBeTruthy();
  });
  it('masks HIGH_RISK_FIELDS', () => {
    render(<ConfigDiffPreview diff={{ merchant_account_reference: { current: 'OLD', proposed: 'NEW' } }} />);
    expect(screen.queryByText('OLD')).toBeNull();
    expect(screen.queryByText('NEW')).toBeNull();
    expect(screen.getAllByText('••••').length).toBeGreaterThan(0);
  });
  it('shows normal field values', () => {
    render(<ConfigDiffPreview diff={{ max_amount_minor: { current: 1000, proposed: 2000 } }} />);
    expect(screen.getByText('1000')).toBeTruthy();
    expect(screen.getByText('2000')).toBeTruthy();
  });
  it('renders JSONB arrays as comma-separated', () => {
    render(<ConfigDiffPreview diff={{ supported_currencies: { current: ['BWP', 'USD'], proposed: ['BWP'] } }} />);
    expect(screen.getByText('BWP, USD')).toBeTruthy();
  });
});

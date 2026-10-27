import { render, screen } from '@testing-library/react';
import { AccountLifecycleStatusBadge } from '../AccountLifecycleStatusBadge';
import { EnvironmentBadge } from '../EnvironmentBadge';

describe('AccountLifecycleStatusBadge', () => {
  it('renders active as success', () => {
    render(<AccountLifecycleStatusBadge status="active" />);
    expect(screen.getByText('Active')).toBeTruthy();
  });
  it('renders suspended as warning', () => {
    render(<AccountLifecycleStatusBadge status="suspended" />);
    expect(screen.getByText('Suspended')).toBeTruthy();
  });
});

describe('EnvironmentBadge', () => {
  it('renders production with PRODUCTION label', () => {
    render(<EnvironmentBadge environment="production" />);
    expect(screen.getByText(/PRODUCTION/)).toBeTruthy();
  });
  it('renders sandbox with SANDBOX label', () => {
    render(<EnvironmentBadge environment="sandbox" />);
    expect(screen.getByText('SANDBOX')).toBeTruthy();
  });
  it('renders mock with MOCK label', () => {
    render(<EnvironmentBadge environment="mock" />);
    expect(screen.getByText('MOCK')).toBeTruthy();
  });
});

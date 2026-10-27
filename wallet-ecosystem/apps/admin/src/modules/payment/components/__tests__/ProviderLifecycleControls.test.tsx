import { render, screen } from '@testing-library/react';
import { ProviderLifecycleControls } from '../ProviderLifecycleControls';

describe('ProviderLifecycleControls', () => {
  it('shows suspend and retire for active status', () => {
    render(<ProviderLifecycleControls status="active" canManage={true} onTransition={jest.fn()} />);
    expect(screen.getByRole('button', { name: /suspend/i })).toBeTruthy();
    expect(screen.getByRole('button', { name: /retire/i })).toBeTruthy();
  });

  it('shows restore and retire for suspended status', () => {
    render(<ProviderLifecycleControls status="suspended" canManage={true} onTransition={jest.fn()} />);
    expect(screen.getByRole('button', { name: /restore/i })).toBeTruthy();
  });

  it('renders nothing for retired status', () => {
    const { container } = render(<ProviderLifecycleControls status="retired" canManage={true} onTransition={jest.fn()} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing when user lacks permission', () => {
    const { container } = render(<ProviderLifecycleControls status="active" canManage={false} onTransition={jest.fn()} />);
    expect(container.firstChild).toBeNull();
  });
});

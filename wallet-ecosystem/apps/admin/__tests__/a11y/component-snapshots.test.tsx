import React from 'react';
import { render } from '@testing-library/react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Dialog } from '@/components/ui/Dialog';
import { Sheet } from '@/components/ui/Sheet';
import { Toast } from '@/components/ui/Toast';
import { Badge } from '@/components/ui/Badge';
import { Tabs } from '@/components/ui/Tabs';
import { Table } from '@/components/ui/Table';
import { Navigation } from '@/components/ui/Navigation';
import { Loading } from '@/components/ui/Loading';
import { Empty } from '@/components/ui/Empty';
import { ErrorState } from '@/components/ui/ErrorState';
import { Confirmation } from '@/components/ui/Confirmation';

describe('Admin UI component snapshots — design token consistency', () => {
  it('Button renders consistently', () => {
    const { container } = render(<Button>Submit</Button>);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Button (variant=secondary) renders consistently', () => {
    const { container } = render(<Button variant="secondary">Cancel</Button>);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Button (disabled) renders consistently', () => {
    const { container } = render(<Button disabled>Submit</Button>);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Input renders consistently', () => {
    const { container } = render(<Input label="Email" type="email" />);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Input (with error) renders consistently', () => {
    const { container } = render(
      <Input label="Email" type="email" error="Invalid email address" />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Select renders consistently', () => {
    const { container } = render(
      <Select
        label="Country"
        options={[
          { value: 'ng', label: 'Nigeria' },
          { value: 'gh', label: 'Ghana' },
        ]}
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Card renders consistently', () => {
    const { container } = render(
      <Card semanticLabel="Account summary">
        <p>Balance: 0</p>
      </Card>,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Dialog (open) renders consistently', () => {
    const { container } = render(
      <Dialog open onClose={() => {}} title="Confirm action">
        <p>Are you sure?</p>
      </Dialog>,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Sheet (open) renders consistently', () => {
    const { container } = render(
      <Sheet open onClose={() => {}} title="Select option">
        <p>Option list</p>
      </Sheet>,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Toast renders consistently', () => {
    const { container } = render(
      <Toast message="Action completed" onDismiss={() => {}} />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Badge (success) renders consistently', () => {
    const { container } = render(<Badge label="Active" variant="success" />);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Badge (error) renders consistently', () => {
    const { container } = render(<Badge label="Blocked" variant="error" />);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Badge (warning) renders consistently', () => {
    const { container } = render(<Badge label="Pending" variant="warning" />);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Tabs renders consistently', () => {
    const { container } = render(
      <Tabs
        tabs={[
          { id: 'overview', label: 'Overview', content: <p>Overview content</p> },
          { id: 'details', label: 'Details', content: <p>Details content</p> },
        ]}
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Table (with data) renders consistently', () => {
    const { container } = render(
      <Table
        caption="User list"
        columns={[{ key: 'name', header: 'Name', render: (r: { name: string }) => r.name }]}
        data={[{ name: 'Alice' }]}
        getRowKey={(r: { name: string }) => r.name}
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Table (empty) renders consistently', () => {
    const { container } = render(
      <Table
        caption="Empty table"
        columns={[{ key: 'name', header: 'Name', render: (r: { name: string }) => r.name }]}
        data={[]}
        getRowKey={(r: { name: string }) => r.name}
        emptyMessage="No users found"
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Navigation renders consistently', () => {
    const { container } = render(
      <Navigation
        items={[
          { href: '/dashboard', label: 'Dashboard' },
          { href: '/users', label: 'Users' },
        ]}
        currentPath="/dashboard"
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Loading renders consistently', () => {
    const { container } = render(<Loading label="Fetching data" />);
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Empty renders consistently', () => {
    const { container } = render(
      <Empty title="No results" description="Try adjusting your filters" />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('ErrorState renders consistently', () => {
    const { container } = render(
      <ErrorState message="Failed to load data" onRetry={() => {}} />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });

  it('Confirmation (open) renders consistently', () => {
    const { container } = render(
      <Confirmation
        open
        onClose={() => {}}
        onConfirm={() => {}}
        title="Delete record"
        message="This cannot be undone."
        destructive
      />,
    );
    expect(container.firstChild).toMatchSnapshot();
  });
});

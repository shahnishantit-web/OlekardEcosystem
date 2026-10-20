import React from 'react';
import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';
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

expect.extend(toHaveNoViolations);

describe('WCAG AA accessibility — shared admin components', () => {
  it('Button has no violations', async () => {
    const { container } = render(<Button>Submit</Button>);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Button (disabled) has no violations', async () => {
    const { container } = render(<Button disabled>Submit</Button>);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Button (loading) has no violations', async () => {
    const { container } = render(<Button loading>Submit</Button>);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Input has no violations', async () => {
    const { container } = render(<Input label="Email" type="email" />);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Input (with error) has no violations', async () => {
    const { container } = render(
      <Input label="Email" type="email" error="Invalid email address" />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Select has no violations', async () => {
    const { container } = render(
      <Select
        label="Country"
        options={[
          { value: 'ng', label: 'Nigeria' },
          { value: 'gh', label: 'Ghana' },
        ]}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Card has no violations', async () => {
    const { container } = render(
      <Card semanticLabel="Account summary">
        <p>Balance: 0</p>
      </Card>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Dialog (open) has no violations', async () => {
    const { container } = render(
      <Dialog open onClose={() => {}} title="Confirm action">
        <p>Are you sure?</p>
      </Dialog>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Sheet (open) has no violations', async () => {
    const { container } = render(
      <Sheet open onClose={() => {}} title="Select option">
        <p>Option list</p>
      </Sheet>,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Toast has no violations', async () => {
    const { container } = render(
      <Toast message="Action completed" onDismiss={() => {}} />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Badge has no violations', async () => {
    const { container } = render(<Badge label="Active" variant="success" />);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Tabs has no violations', async () => {
    const { container } = render(
      <Tabs
        tabs={[
          { id: 'overview', label: 'Overview', content: <p>Overview content</p> },
          { id: 'details', label: 'Details', content: <p>Details content</p> },
        ]}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Table (with data) has no violations', async () => {
    const { container } = render(
      <Table
        caption="User list"
        columns={[{ key: 'name', header: 'Name', render: (r: { name: string }) => r.name }]}
        data={[{ name: 'Alice' }]}
        getRowKey={(r: { name: string }) => r.name}
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Table (empty) has no violations', async () => {
    const { container } = render(
      <Table
        caption="Empty table"
        columns={[{ key: 'name', header: 'Name', render: (r: { name: string }) => r.name }]}
        data={[]}
        getRowKey={(r: { name: string }) => r.name}
        emptyMessage="No users found"
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Navigation has no violations', async () => {
    const { container } = render(
      <Navigation
        items={[
          { href: '/dashboard', label: 'Dashboard' },
          { href: '/users', label: 'Users' },
        ]}
        currentPath="/dashboard"
      />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Loading has no violations', async () => {
    const { container } = render(<Loading label="Fetching data" />);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Empty has no violations', async () => {
    const { container } = render(
      <Empty title="No results" description="Try adjusting your filters" />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('ErrorState has no violations', async () => {
    const { container } = render(
      <ErrorState message="Failed to load data" onRetry={() => {}} />,
    );
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Confirmation (open) has no violations', async () => {
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
    expect(await axe(container)).toHaveNoViolations();
  });
});

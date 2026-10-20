import React from 'react';
import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { Loading } from '@/components/ui/Loading';
import { Empty } from '@/components/ui/Empty';
import { ErrorState } from '@/components/ui/ErrorState';
import { Navigation } from '@/components/ui/Navigation';
import { Select } from '@/components/ui/Select';
import { Table } from '@/components/ui/Table';
import { Toast } from '@/components/ui/Toast';
import { Dialog } from '@/components/ui/Dialog';
import { Tabs } from '@/components/ui/Tabs';
import { Sheet } from '@/components/ui/Sheet';
import { Confirmation } from '@/components/ui/Confirmation';

expect.extend(toHaveNoViolations);

test('Button has no accessibility violations', async () => {
  const { container } = render(<Button label="Submit">Submit</Button>);
  expect(await axe(container)).toHaveNoViolations();
});

test('Input has no accessibility violations', async () => {
  const { container } = render(<Input id="test-input" label="Email" type="email" />);
  expect(await axe(container)).toHaveNoViolations();
});

test('Input with error has no accessibility violations', async () => {
  const { container } = render(
    <Input id="test-input-err" label="Email" type="email" error="Invalid email" />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Badge has no accessibility violations', async () => {
  const { container } = render(<Badge label="Active" variant="success" />);
  expect(await axe(container)).toHaveNoViolations();
});

test('Card has no accessibility violations', async () => {
  const { container } = render(<Card title="Test card"><p>Content</p></Card>);
  expect(await axe(container)).toHaveNoViolations();
});

test('Loading has no accessibility violations', async () => {
  const { container } = render(<Loading label="Loading data..." />);
  expect(await axe(container)).toHaveNoViolations();
});

test('Empty has no accessibility violations', async () => {
  const { container } = render(
    <Empty title="No results" description="Try adjusting your filters" />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('ErrorState has no accessibility violations', async () => {
  const { container } = render(
    <ErrorState title="Error" message="Something went wrong" />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Navigation has no accessibility violations', async () => {
  const { container } = render(
    <Navigation
      items={[
        { href: '/dashboard', label: 'Dashboard', isCurrent: true },
        { href: '/settings', label: 'Settings' },
      ]}
    />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Select has no accessibility violations', async () => {
  const { container } = render(
    <Select
      id="test-select"
      label="Country"
      options={[{ value: 'bw', label: 'Botswana' }]}
    />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Table has no accessibility violations', async () => {
  const { container } = render(
    <Table
      caption="Users"
      columns={[{ key: 'name', label: 'Name' }]}
      rows={[{ name: 'Alice' }]}
      getRowKey={(r) => r.name as string}
    />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Toast has no accessibility violations', async () => {
  const { container } = render(<Toast message="Saved!" onDismiss={jest.fn()} />);
  expect(await axe(container)).toHaveNoViolations();
});

test('Tabs has no accessibility violations', async () => {
  const { container } = render(
    <Tabs
      tabs={[
        { id: 'tab1', label: 'Tab 1', content: <p>Content 1</p> },
        { id: 'tab2', label: 'Tab 2', content: <p>Content 2</p> },
      ]}
    />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Dialog has no accessibility violations when open', async () => {
  const { container } = render(
    <Dialog open={true} onClose={jest.fn()} title="Confirm action">
      <p>Are you sure?</p>
    </Dialog>,
  );
  expect(await axe(container)).toHaveNoViolations();
});

test('Confirmation has no accessibility violations when open', async () => {
  const { container } = render(
    <Confirmation
      open={true}
      onConfirm={jest.fn()}
      onCancel={jest.fn()}
      title="Delete item"
      message="This cannot be undone."
    />,
  );
  expect(await axe(container)).toHaveNoViolations();
});

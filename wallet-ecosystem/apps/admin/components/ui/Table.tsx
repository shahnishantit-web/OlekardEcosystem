import type { ReactNode } from 'react';

interface Column<T> {
  key: string;
  header?: string;
  label?: string;    // alias for header
  render?: (row: T) => ReactNode;
}

interface TableProps<T> {
  columns?: Column<T>[];
  data?: T[];
  rows?: T[];           // alias for data
  caption?: string;
  'aria-label'?: string;
  emptyMessage?: string;
  getRowKey?: (row: T) => string;
  rowKey?: (row: T) => string;  // alias for getRowKey
  children?: ReactNode;
}

export function Table<T extends object>({
  columns,
  data,
  rows,
  caption,
  emptyMessage = 'No data available',
  getRowKey,
  rowKey,
  children,
  'aria-label': ariaLabel,
}: TableProps<T>) {
  const resolvedCols = columns ?? [];
  const actualData = data ?? rows;
  const actualGetRowKey = getRowKey ?? rowKey;

  // Children (HTML passthrough) mode — when no column definitions are provided
  if (resolvedCols.length === 0 && children !== undefined) {
    return (
      <div className="overflow-x-auto">
        <table
          className="w-full text-sm text-left border-collapse"
          aria-label={ariaLabel}
        >
          {caption && <caption className="sr-only">{caption}</caption>}
          {children}
        </table>
      </div>
    );
  }

  const tableData = actualData ?? [];
  const keyFn =
    actualGetRowKey ??
    ((row: T) => String((row as { id?: unknown }).id ?? JSON.stringify(row)));

  return (
    <div className="overflow-x-auto">
      <table
        className="w-full text-sm text-left border-collapse"
        aria-label={ariaLabel}
      >
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
            {resolvedCols.map((col) => (
              <th
                key={col.key}
                scope="col"
                className="px-4 py-3 font-medium text-text-secondary"
              >
                {col.header ?? col.label ?? col.key}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {tableData.length === 0 ? (
            <tr>
              <td
                colSpan={resolvedCols.length}
                className="px-4 py-8 text-center text-text-secondary"
              >
                {emptyMessage}
              </td>
            </tr>
          ) : (
            tableData.map((row) => (
              <tr
                key={keyFn(row)}
                className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg"
              >
                {resolvedCols.map((col) => (
                  <td key={col.key} className="px-4 py-3 text-text-primary">
                    {col.render
                      ? col.render(row)
                      : String((row as Record<string, unknown>)[col.key] ?? '')}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

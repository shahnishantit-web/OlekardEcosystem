import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';

export interface DataColumn<T> {
  key: string;
  header: string;
  render: (row: T) => React.ReactNode;
  sortable?: boolean;
  className?: string;
}

export interface SortState {
  key: string;
  direction: 'asc' | 'desc';
}

interface DataTableProps<T> {
  columns: DataColumn<T>[];
  data: T[];
  caption?: string;
  emptyMessage?: string;
  getRowKey: (row: T) => string;
  loading?: boolean;
  skeletonRows?: number;
  sortState?: SortState;
  onSort?: (key: string) => void;
  onRowClick?: (row: T) => void;
}

function SkeletonRow({ cols }: { cols: number }) {
  return (
    <tr className="border-b border-ok-border">
      {Array.from({ length: cols }).map((_, i) => (
        <td key={i} className="px-4 py-2.5">
          <div className="h-3 rounded bg-neutral-200 animate-pulse" style={{ width: `${60 + (i * 17) % 30}%` }} />
        </td>
      ))}
    </tr>
  );
}

function SortIcon({ columnKey, sortState }: { columnKey: string; sortState?: SortState }) {
  if (!sortState || sortState.key !== columnKey) {
    return <ChevronsUpDown size={12} className="opacity-30" aria-hidden="true" />;
  }
  return sortState.direction === 'asc'
    ? <ChevronUp size={12} aria-hidden="true" />
    : <ChevronDown size={12} aria-hidden="true" />;
}

export function DataTable<T>({
  columns,
  data,
  caption,
  emptyMessage = 'No data available',
  getRowKey,
  loading,
  skeletonRows = 8,
  sortState,
  onSort,
  onRowClick,
}: DataTableProps<T>) {
  return (
    <div className="overflow-x-auto rounded-[11px] border border-ok-card-border">
      <table className="w-full text-sm text-left border-collapse">
        {caption && <caption className="sr-only">{caption}</caption>}

        <thead className="sticky top-0 z-10 bg-neutral-50">
          <tr className="border-b border-ok-border border-t border-ok-border">
            {columns.map((col) => (
              <th
                key={col.key}
                scope="col"
                className={[
                  'px-4 py-2.5 text-xs font-semibold text-text-secondary uppercase tracking-wide whitespace-nowrap',
                  col.sortable && onSort
                    ? 'cursor-pointer select-none hover:text-text-primary focus-visible:outline-2'
                    : '',
                  col.className ?? '',
                ].join(' ')}
                tabIndex={col.sortable && onSort ? 0 : undefined}
                onClick={col.sortable && onSort ? () => onSort(col.key) : undefined}
                onKeyDown={col.sortable && onSort ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSort(col.key); } } : undefined}
                aria-sort={
                  sortState?.key === col.key
                    ? sortState.direction === 'asc' ? 'ascending' : 'descending'
                    : undefined
                }
              >
                <span className="flex items-center gap-1">
                  {col.header}
                  {col.sortable && onSort && (
                    <SortIcon columnKey={col.key} {...(sortState !== undefined ? { sortState } : {})} />
                  )}
                </span>
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {loading ? (
            Array.from({ length: skeletonRows }).map((_, i) => (
              <SkeletonRow key={i} cols={columns.length} />
            ))
          ) : data.length === 0 ? (
            <tr>
              <td
                colSpan={columns.length}
                className="px-4 py-10 text-center text-sm text-text-secondary"
              >
                {emptyMessage}
              </td>
            </tr>
          ) : (
            data.map((row) => (
              <tr
                key={getRowKey(row)}
                className={[
                  'border-b border-[#f0f1f4] last:border-0',
                  'hover:bg-ok-hover-bg transition-colors',
                  onRowClick ? 'cursor-pointer' : '',
                ].join(' ')}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
              >
                {columns.map((col) => (
                  <td
                    key={col.key}
                    className={['px-4 py-2.5 text-text-primary', col.className ?? ''].join(' ')}
                  >
                    {col.render(row)}
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

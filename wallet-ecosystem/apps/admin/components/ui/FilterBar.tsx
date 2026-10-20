'use client';

import { useState, useEffect, useRef } from 'react';
import { Search, X } from 'lucide-react';

export interface FilterBarOption {
  value: string;
  label: string;
}

export interface FilterBarValues {
  search?: string;
  status?: string;
  from?: string;
  to?: string;
}

interface FilterBarProps {
  onFilter: (values: FilterBarValues) => void;
  statusOptions?: FilterBarOption[];
  searchPlaceholder?: string;
  showDateRange?: boolean;
  debounceMs?: number;
}

export function FilterBar({
  onFilter,
  statusOptions,
  searchPlaceholder = 'Search…',
  showDateRange = false,
  debounceMs = 300,
}: FilterBarProps) {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Debounce the filter callback
  useEffect(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => {
      const values: FilterBarValues = {};
      if (search) values.search = search;
      if (status) values.status = status;
      if (from) values.from = from;
      if (to) values.to = to;
      onFilter(values);
    }, debounceMs);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, status, from, to]);

  const hasFilters = Boolean(search || status || from || to);

  const clearAll = () => {
    setSearch('');
    setStatus('');
    setFrom('');
    setTo('');
  };

  const inputClass =
    'bg-ok-info-bg border border-ok-border rounded-md text-sm text-text-primary placeholder:text-text-secondary/40 focus:outline-none focus:border-ok-primary transition-colors';

  return (
    <div className="flex flex-wrap items-center gap-2" role="search" aria-label="Filter results">
      {/* Text search */}
      <div className="relative min-w-[180px]">
        <Search
          size={13}
          className="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-secondary/50 pointer-events-none"
          aria-hidden="true"
        />
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={searchPlaceholder}
          aria-label={searchPlaceholder}
          className={`${inputClass} pl-8 pr-3 py-1.5`}
        />
      </div>

      {/* Status filter */}
      {statusOptions && statusOptions.length > 0 && (
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          aria-label="Filter by status"
          className={`${inputClass} px-3 py-1.5 pr-7 cursor-pointer`}
        >
          <option value="">All statuses</option>
          {statusOptions.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      )}

      {/* Date range */}
      {showDateRange && (
        <>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            aria-label="From date"
            className={`${inputClass} px-3 py-1.5`}
          />
          <span className="text-xs text-text-secondary">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            aria-label="To date"
            className={`${inputClass} px-3 py-1.5`}
          />
        </>
      )}

      {/* Clear */}
      {hasFilters && (
        <button
          type="button"
          onClick={clearAll}
          aria-label="Clear all filters"
          className="flex items-center gap-1 text-xs text-text-secondary hover:text-text-primary transition-colors"
        >
          <X size={12} aria-hidden="true" />
          Clear
        </button>
      )}
    </div>
  );
}

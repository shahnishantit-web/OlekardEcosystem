'use client';
import Link from 'next/link';
import { Button } from '@/components/ui/Button';
export const field = 'block w-full rounded-lg border border-ok-border-strong bg-ok-surface p-2 text-text-primary focus-visible:outline-2';
export function IntegrationNav() { return <nav aria-label="Integration management" className="flex flex-wrap gap-4 mb-6"><Link className="underline" href="/integrations">Provider health</Link><Link className="underline" href="/integrations/providers">Partners</Link><Link className="underline" href="/integrations/clients">API clients</Link><Link className="underline" href="/integrations/webhooks">Webhooks</Link></nav>; }
export function Pagination({ page, lastPage, onChange }: {
    page: number;
    lastPage: number;
    onChange: (n: number) => void;
}) { return <div className="flex gap-3 items-center mt-4"><Button variant="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>Previous</Button><span>Page {page} of {lastPage}</span><Button variant="secondary" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>Next</Button></div>; }

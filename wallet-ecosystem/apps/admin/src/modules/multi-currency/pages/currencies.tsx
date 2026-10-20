'use client';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Table } from '@/components/ui/Table';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
interface Currency {
    code: string;
    name: string;
    decimal_places: number;
    is_active: boolean;
    is_base: boolean | null;
    pending_change_request_id: string | null;
    pending_is_active: boolean | null;
}
function message(e: unknown): string { return (e as {
    error?: {
        message?: string;
    };
})?.error?.message ?? (e instanceof Error ? e.message : 'Currency update failed.'); }
export function CurrenciesPage() {
    const { authenticatedClient, hasPermission } = useAuth();
    const [items, setItems] = useState<Currency[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const load = useCallback(async () => { if (!authenticatedClient)
        return; setLoading(true); try {
        const r = await authenticatedClient.get<Currency[]>('/v1/admin/currencies');
        setItems(r.data);
        setError(null);
    }
    catch (e) {
        setError(message(e));
    }
    finally {
        setLoading(false);
    } }, [authenticatedClient]);
    useEffect(() => { void load(); }, [load]);
    async function change(c: Currency, approve: boolean) {
        if (!authenticatedClient)
            return;
        setBusy(true);
        setNotice('');
        try {
            const options = { idempotencyKey: crypto.randomUUID() };
            if (approve)
                await authenticatedClient.post('/v1/admin/currencies/' + c.code + '/activate/' + c.pending_change_request_id, {}, options);
            else
                await authenticatedClient.patch('/v1/admin/currencies/' + c.code, { is_active: !c.is_active }, options);
            setNotice(approve ? 'Approved currency status applied.' : 'Change submitted. A different administrator must approve it.');
            await load();
        }
        catch (e) {
            setNotice(message(e));
        }
        finally {
            setBusy(false);
        }
    }
    if (loading)
        return <Loading />;
    if (error !== null)
        return <ErrorState message={error} onRetry={load}/>;
    const columns = [
        { key: 'code', header: 'Code', render: (c: Currency) => c.code },
        { key: 'name', header: 'Name', render: (c: Currency) => c.name },
        { key: 'status', header: 'Status', render: (c: Currency) => c.is_active ? 'Active' : 'Inactive' },
        { key: 'precision', header: 'Precision', render: (c: Currency) => c.decimal_places },
        { key: 'base', header: 'Base', render: (c: Currency) => c.is_base === null ? 'Unconfigured' : c.is_base ? 'Yes' : 'No' },
        { key: 'actions', header: 'Approval', render: (c: Currency) => c.pending_change_request_id !== null ? <div><p>Pending: {c.pending_is_active === true ? 'Enable' : 'Disable'}</p>{hasPermission('configuration.approve') && <Button disabled={busy} onClick={() => void change(c, true)}>Approve {c.code}</Button>}</div> : <Button disabled={busy || !hasPermission('multi_currency.manage_currencies')} onClick={() => void change(c, false)}>{c.is_active ? 'Request disable' : 'Request enable'} {c.code}</Button> }
    ];
    return <PageContainer><PageHeader title="Currency Management"/><p>Financial status changes require a different administrator to approve them. Precision changes require a separate financial migration.</p><Link href="/multi-currency/fx-rates">Manage exchange rates</Link>{notice && <p role="status">{notice}</p>}<Card><div className="overflow-x-auto"><Table columns={columns} data={items} getRowKey={c => c.code} emptyMessage="No currencies configured."/></div></Card></PageContainer>;
}

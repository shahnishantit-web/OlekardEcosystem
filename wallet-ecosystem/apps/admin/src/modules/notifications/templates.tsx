'use client';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { NotificationsApi, errorMessage, type Template } from './api';
import { NotificationNav, field } from './shared';
export function NotificationTemplatesPage() {
    const { authenticatedClient: client, hasPermission } = useAuth(), api = useMemo(() => client ? new NotificationsApi(client) : null, [client]);
    const read = hasPermission('notifications.templates.read'), write = hasPermission('notifications.templates.write');
    const [rows, setRows] = useState<Template[] | null>(null), [selected, setSelected] = useState<Template | null>(null), [error, setError] = useState(''), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false), [reload, setReload] = useState(0);
    const [samples, setSamples] = useState<Record<string, string>>({}), [preview, setPreview] = useState<{
        subject: string;
        body: string;
    } | null>(null);
    const [event, setEvent] = useState(''), [channel, setChannel] = useState(''), [language, setLanguage] = useState('');
    const editor = useRef<HTMLInputElement>(null), testId = useRef<string | null>(null);
    useEffect(() => { let active = true; if (api && read) {
        setRows(null);
        api.templates().then(r => { if (active)
            setRows(r); }).catch(e => { if (active)
            setError(errorMessage(e)); });
    } return () => { active = false; }; }, [api, read, reload]);
    const selectedId = selected?.id;
    useEffect(() => { if (selectedId !== undefined)
        editor.current?.focus(); }, [selectedId]);
    function choose(t: Template) { setSelected({ ...t }); setPreview(null); setError(''); setNotice(''); testId.current = null; const names = Array.isArray(t.variables) ? t.variables : Object.keys(t.variables ?? {}); setSamples(Object.fromEntries(names.map(n => [n, 'Sample ' + n]))); }
    async function perform(action: 'save' | 'preview' | 'test') {
        if (!api || !selected)
            return;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            if (action === 'save') {
                const saved = await api.edit(selected);
                setSelected(saved);
                setRows(previous => previous?.map(t => t.id === saved.id ? saved : t) ?? null);
                setNotice('Template saved.');
            }
            else if (action === 'preview')
                setPreview(await api.preview(selected, samples));
            else {
                testId.current ??= crypto.randomUUID();
                await api.test(selected, samples, testId.current);
                setNotice('Test notification queued to your own account using the saved template.');
            }
        }
        catch (e) {
            setError(errorMessage(e));
        }
        finally {
            setBusy(false);
        }
    }
    if (!read)
        return <PageContainer><p role="alert">Access denied</p></PageContainer>;
    const groups = (rows ?? []).flatMap(t => t.supported_channels.map(c => ({ template: t, channel: c, language: t.language ?? 'Unspecified' }))).filter(g => (!event || g.template.key === event) && (!channel || g.channel === channel) && (!language || g.language === language));
    return <PageContainer><PageHeader title="Notification templates" description="Review event content and manage shared notification copy."/><NotificationNav />
 {error && <ErrorState message={error} onRetry={() => { setError(''); setReload(n => n + 1); }}/>}<p role="status" className="text-sm mb-3">{notice}</p>
 {!rows ? <Loading label="Loading templates"/> : <>
 <div className="grid gap-3 sm:grid-cols-3 mb-5">
 <label>Event type<select aria-label="Event type" className={field} value={event} onChange={e => setEvent(e.target.value)}><option value="">All events</option>{rows.map(t => <option key={t.id}>{t.key}</option>)}</select></label>
 <label>Channel<select aria-label="Channel" className={field} value={channel} onChange={e => setChannel(e.target.value)}><option value="">All channels</option>{['email', 'sms', 'push', 'in_app'].map(c => <option key={c}>{c}</option>)}</select></label>
 <label>Language<select aria-label="Language" className={field} value={language} onChange={e => setLanguage(e.target.value)}><option value="">All languages</option>{Array.from(new Set(rows.map(t => t.language ?? 'Unspecified'))).map(l => <option key={l}>{l}</option>)}</select></label></div>
 <div className="grid gap-5 xl:grid-cols-2"><div className="space-y-3">{!groups.length ? <Card>No templates match these filters.</Card> : groups.map(g => <Card key={g.template.id + g.channel}><h2 className="font-semibold break-all">{g.template.key}</h2><p className="text-sm text-text-secondary mb-3">{g.channel.toUpperCase()} · {g.language}</p><Button variant="secondary" onClick={() => choose(g.template)} aria-label={'Edit ' + g.template.key + ' ' + g.channel}>Edit template</Button></Card>)}</div>
 {selected && <Card><h2 className="text-lg font-semibold mb-2">Edit {selected.key}</h2><p className="text-sm text-text-secondary mb-4">Content is shared across the supported channels. Test sends use the saved version and your own account.</p>
 <label className="block mb-3">Subject<input aria-label="Subject" ref={editor} className={field} maxLength={500} value={selected.subject_template} readOnly={!write} onChange={e => { setSelected({ ...selected, subject_template: e.target.value }); setPreview(null); }}/></label>
 <label className="block mb-3">Body<textarea aria-label="Body" className={field} rows={5} maxLength={20000} value={selected.body_template} readOnly={!write} onChange={e => { setSelected({ ...selected, body_template: e.target.value }); setPreview(null); }}/></label>
 <label className="block mb-4">Language tag<input className={field} aria-label="Language tag" placeholder="Unspecified" value={selected.language ?? ''} readOnly={!write} onChange={e => setSelected({ ...selected, language: e.target.value || null })}/></label>
 <h3 className="font-medium mb-2">Sample variables</h3>{Object.entries(samples).map(([n, v]) => <label className="block mb-2" key={n}>{n}<input aria-label={n} className={field} value={v} onChange={e => { setSamples({ ...samples, [n]: e.target.value }); setPreview(null); }}/></label>)}
 <div className="flex flex-wrap gap-2 mt-4"><Button disabled={busy || !selected.subject_template || !selected.body_template} variant="secondary" onClick={() => perform('preview')}>Preview</Button>{write && <><Button disabled={busy} onClick={() => perform('save')}>Save template</Button><Button variant="secondary" disabled={busy || !selected.active} onClick={() => perform('test')}>Send test to me</Button></>}</div>
 {preview && <section aria-label="Template preview" className="mt-5 border-t border-ok-border pt-4"><h3 className="font-semibold">Preview</h3><p className="font-medium break-words">{preview.subject}</p><p className="whitespace-pre-wrap break-words mt-2">{preview.body}</p></section>}
 </Card>}</div></>}</PageContainer>;
}

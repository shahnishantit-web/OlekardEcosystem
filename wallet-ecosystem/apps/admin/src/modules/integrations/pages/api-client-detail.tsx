'use client';
import { ApiClientListPage } from './api-client-list';
export function ApiClientDetailPage({ clientId }: {
    clientId: string;
}) { return <ApiClientListPage clientId={clientId}/>; }

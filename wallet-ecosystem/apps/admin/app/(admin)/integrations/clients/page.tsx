import { ApiClientListPage } from '@/src/modules/integrations/pages/api-client-list';
export default function Clients({ searchParams }: {
    searchParams: {
        integration_id?: string;
    };
}) { return <ApiClientListPage partnerId={searchParams.integration_id}/>; }

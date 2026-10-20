import { ProviderHealthPage } from '@/src/modules/integrations/pages/provider-health';
export default function ProviderHealth({ params }: {
    params: {
        id: string;
    };
}) { return <ProviderHealthPage providerId={params.id}/>; }

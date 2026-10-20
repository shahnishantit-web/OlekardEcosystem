import { AlertDetailPage } from '@/src/modules/security/pages/alert-detail';
export default function AlertDetailRoute({ params }: {
    params: {
        id: string;
    };
}) { return <AlertDetailPage id={params.id}/>; }

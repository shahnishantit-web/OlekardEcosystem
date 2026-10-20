import { CaseDetailPage } from '@/src/modules/support/pages/case-detail';
export default function Page({ params }: {
    params: {
        id: string;
    };
}) { return <CaseDetailPage id={params.id}/>; }

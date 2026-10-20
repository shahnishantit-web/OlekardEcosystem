import { FindingDetailPage } from '@/src/modules/security/pages/finding-detail';
export default function FindingDetailRoute({params}:{params:{id:string}}){return <FindingDetailPage id={params.id}/>;}

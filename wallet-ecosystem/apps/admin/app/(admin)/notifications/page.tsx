import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { NotificationNav } from '@/src/modules/notifications/shared';
export default function NotificationsPage() { return <PageContainer><PageHeader title="Notification management" description="Manage templates, delivery outcomes and event rules."/><NotificationNav /></PageContainer>; }

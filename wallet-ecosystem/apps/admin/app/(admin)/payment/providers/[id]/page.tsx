import { PaymentProviderDetailPage } from '@/src/modules/payment/pages/payment-provider-detail';

export default function Page({ params }: { params: { id: string } }) {
  return <PaymentProviderDetailPage providerId={params.id} />;
}

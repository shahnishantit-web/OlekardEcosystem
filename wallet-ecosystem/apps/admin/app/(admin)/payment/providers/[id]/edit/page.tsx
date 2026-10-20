import { PaymentProviderEditPage } from '@/src/modules/payment/pages/payment-provider-edit';

export default function Page({ params }: { params: { id: string } }) {
  return <PaymentProviderEditPage providerId={params.id} />;
}

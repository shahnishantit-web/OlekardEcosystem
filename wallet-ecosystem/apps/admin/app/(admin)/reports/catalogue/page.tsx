import { redirect } from 'next/navigation';

export default function Page() {
  redirect('/reports#request-new-report');
}

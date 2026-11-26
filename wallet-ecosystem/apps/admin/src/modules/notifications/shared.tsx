'use client';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
export const field = 'block w-full rounded-lg border border-ok-border-strong bg-ok-surface p-2 text-text-primary focus-visible:outline-2';
const notificationEventLabels: Record<string, string> = {
    'payment.qr.received': 'QR Payment Received',
    'payment.qr.sent': 'QR Payment Sent',
    'payment.funding.received': 'Wallet Funded',
    'payment.transfer.received': 'Transfer Received',
    'payment.transfer.sent': 'Transfer Sent',
    'payment.withdrawal.processed': 'Withdrawal Processed',
    'payment.commission.credited': 'Commission Credited',
    'payment.bill.paid': 'Bill Payment Completed',
    'payment.receipt.payment_confirmed': 'Payment Receipt Confirmed',
    'payment.receipt.refund_confirmed': 'Refund Receipt Confirmed',
    payment_confirmed: 'Payment Confirmed',
    payment_failed: 'Payment Failed',
    payment_reversed: 'Payment Reversed',
    refund_confirmed: 'Refund Confirmed',
    'transaction.reversed': 'Transaction Reversed',
    'security.new_device': 'New Device Sign-in',
    'security.password_changed': 'PIN Changed',
    'security.suspicious_activity': 'Suspicious Activity Alert',
    'security.account_frozen_aml': 'Account Restriction Notice',
    'support.dispute_refunded': 'Dispute Refund Processed',
    'support.case_escalated': 'Support Case Escalated',
    'events.event_cancelled': 'Event Cancelled',
    'events.registration.confirmed': 'Event Registration Confirmed',
    'events.paid_tickets.issued': 'Paid Event Tickets Issued',
    'events.invitation.received': 'Event Invitation Received',
    'funeral.collection.opened': 'Funeral Assistance Collection Opened',
    'report.job.completed': 'Report Ready',
    'report.job.failed': 'Report Generation Failed',
    'integration.webhook.dead_lettered': 'Integration Event Needs Attention',
};

export function notificationEventLabel(event: string): string {
    return notificationEventLabels[event] ?? event.split(/[._]+/).filter(Boolean)
        .map(part => part.charAt(0).toUpperCase() + part.slice(1)).join(' ');
}

const notificationEventDescriptions: Record<string, string> = {
    'payment.qr.received': 'Confirms that a merchant received a customer payment made by scanning a QR code.',
    'payment.qr.sent': 'Confirms that a customer payment made by QR code was completed.',
    'payment.funding.received': 'Confirms that funds were added to a customer wallet.',
    'payment.transfer.received': 'Tells the recipient that a wallet transfer has arrived.',
    'payment.transfer.sent': 'Confirms that a wallet transfer was sent to its recipient.',
    'payment.withdrawal.processed': 'Confirms that a requested wallet withdrawal was processed.',
    'payment.commission.credited': 'Tells an agent or eligible recipient that commission was credited.',
    'payment.bill.paid': 'Confirms that a bill or service payment was completed.',
    'payment.receipt.payment_confirmed': 'Sends the payer a receipt after payment confirmation is recorded.',
    'payment.receipt.refund_confirmed': 'Sends the payer a receipt after a refund is confirmed.',
    payment_confirmed: 'Notifies the payer when the payment event is confirmed by the payment workflow.',
    payment_failed: 'Notifies the payer when a payment attempt cannot be completed.',
    payment_reversed: 'Notifies the payer when a previously recorded payment is reversed.',
    refund_confirmed: 'Notifies the payer when a refund is confirmed by the payment workflow.',
    'transaction.reversed': 'Notifies the wallet owner when a transaction reversal is recorded.',
    'security.new_device': 'Alerts a customer after a new device is detected signing in to their account.',
    'security.password_changed': 'Confirms a PIN change and advises the account owner what to do if it was unexpected.',
    'security.suspicious_activity': 'Alerts the account owner when suspicious account activity is detected.',
    'security.account_frozen_aml': 'Notifies the account owner when an account restriction is applied.',
    'support.dispute_refunded': 'Confirms that a refund related to a support dispute has been processed.',
    'support.case_escalated': 'Notifies the assigned support recipient that a case needs senior review.',
    'events.event_cancelled': 'Notifies ticket holders after an event cancellation takes effect.',
    'events.registration.confirmed': 'Confirms a successful free or complimentary event registration and ticket issuance.',
    'events.paid_tickets.issued': 'Confirms payment and ticket issuance after a paid event order is fulfilled.',
    'events.invitation.received': 'Notifies an invited customer when an event invitation is available in My Tickets.',
    'funeral.collection.opened': 'Notifies an eligible household representative when a Funeral Assistance contribution collection opens.',
    'report.job.completed': 'Tells report recipients that a scheduled report is ready.',
    'report.job.failed': 'Tells report recipients that report generation failed and may need a retry.',
    'integration.webhook.dead_lettered': 'Alerts administrators when an integration webhook exhausts retries and requires investigation.',
};

export function notificationEventDescription(event: string): string {
    return notificationEventDescriptions[event]
        ?? `Explains the ${notificationEventLabel(event).toLowerCase()} notification and when it is sent.`;
}

export function NotificationNav() { const { hasPermission } = useAuth(); return <nav aria-label="Notification management" className="flex flex-wrap gap-4 mb-6 text-sm">{[['templates', 'Templates'], ['log', 'Delivery log'], ['rules', 'Rules']].map(([path, label]) => hasPermission('notifications.' + path + '.read') && <Link key={path} className="underline" href={'/notifications/' + path}>{label}</Link>)}</nav>; }

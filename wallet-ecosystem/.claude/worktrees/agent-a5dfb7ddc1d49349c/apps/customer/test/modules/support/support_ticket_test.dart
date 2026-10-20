import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/support/domain/entities/support_ticket.dart';

void main() {
  group('SupportTicket entity', () {
    final ticket = SupportTicket(
      id: 't-001',
      referenceNumber: 'TKT-2024-0001',
      subject: 'Unable to withdraw',
      category: 'Wallet',
      status: TicketStatus.open,
      priority: TicketPriority.medium,
      messages: [
        TicketMessage(
          id: 'm-001',
          body: 'I cannot withdraw funds.',
          isFromSupport: false,
          createdAt: DateTime(2024, 1, 1),
        ),
      ],
      createdAt: DateTime(2024, 1, 1),
      updatedAt: DateTime(2024, 1, 1),
    );

    test('has one message', () {
      expect(ticket.messages.length, 1);
    });

    test('message is from customer', () {
      expect(ticket.messages.first.isFromSupport, isFalse);
    });

    test('status is open', () {
      expect(ticket.status, TicketStatus.open);
    });
  });

  group('Dispute entity', () {
    final dispute = Dispute(
      id: 'd-001',
      referenceNumber: 'DSP-2024-0001',
      transactionReference: 'TXN-20240101-0001',
      reason: 'Unauthorized transaction',
      status: DisputeStatus.open,
      disputedAmountMinorUnits: 5000,
      currencyCode: 'KES',
      createdAt: DateTime(2024, 1, 1),
    );

    test('disputed amount is accessible', () {
      expect(dispute.disputedAmountMinorUnits, 5000);
    });

    test('status is open', () {
      expect(dispute.status, DisputeStatus.open);
    });
  });
}

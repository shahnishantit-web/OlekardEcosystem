enum TicketStatus { open, inProgress, resolved, closed }
enum TicketPriority { low, medium, high }
enum DisputeStatus { open, underReview, resolved, closed }

class SupportTicket {
  final String id;
  final String referenceNumber;
  final String subject;
  final String category;
  final TicketStatus status;
  final TicketPriority priority;
  final List<TicketMessage> messages;
  final DateTime createdAt;
  final DateTime updatedAt;

  const SupportTicket({
    required this.id,
    required this.referenceNumber,
    required this.subject,
    required this.category,
    required this.status,
    required this.priority,
    required this.messages,
    required this.createdAt,
    required this.updatedAt,
  });
}

class TicketMessage {
  final String id;
  final String body;
  final bool isFromSupport;
  final DateTime createdAt;

  const TicketMessage({
    required this.id,
    required this.body,
    required this.isFromSupport,
    required this.createdAt,
  });
}

class Dispute {
  final String id;
  final String referenceNumber;
  final String transactionReference;
  final String reason;
  final DisputeStatus status;
  final int disputedAmountMinorUnits;
  final String currencyCode;
  final DateTime createdAt;

  const Dispute({
    required this.id,
    required this.referenceNumber,
    required this.transactionReference,
    required this.reason,
    required this.status,
    required this.disputedAmountMinorUnits,
    required this.currencyCode,
    required this.createdAt,
  });
}

class CreateTicketRequest {
  final String subject;
  final String category;
  final String message;

  const CreateTicketRequest({
    required this.subject,
    required this.category,
    required this.message,
  });

  Map<String, dynamic> toJson() => {
        'subject': subject,
        'category': category,
        'message': message,
      };
}

class RaiseDisputeRequest {
  final String transactionReference;
  final String reason;

  const RaiseDisputeRequest({
    required this.transactionReference,
    required this.reason,
  });

  Map<String, dynamic> toJson() => {
        'transaction_reference': transactionReference,
        'reason': reason,
      };
}

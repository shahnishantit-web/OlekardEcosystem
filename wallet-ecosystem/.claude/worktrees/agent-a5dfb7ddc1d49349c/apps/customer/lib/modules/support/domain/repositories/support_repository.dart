import '../../../../core/api/paginated_result.dart';
import '../entities/support_ticket.dart';

abstract class SupportRepository {
  Future<PaginatedResult<SupportTicket>> getTickets(int page);
  Future<SupportTicket> getTicket(String ticketId);
  Future<SupportTicket> createTicket(CreateTicketRequest request);
  Future<SupportTicket> replyToTicket(String ticketId, String message);
  Future<PaginatedResult<Dispute>> getDisputes(int page);
  Future<Dispute> getDispute(String disputeId);
  Future<Dispute> raiseDispute(RaiseDisputeRequest request);
}

import 'package:dio/dio.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/api/paginated_result.dart';
import '../../domain/entities/support_ticket.dart';
import '../../domain/repositories/support_repository.dart';

class SupportRepositoryImpl implements SupportRepository {
  final Dio _dio;

  SupportRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<PaginatedResult<SupportTicket>> getTickets(int page) async {
    final res = await _dio.get('/v1/customer/support/tickets', queryParameters: {'page': page});
    return PaginatedResult.fromJson(res.data as Map<String, dynamic>, _ticketFromJson);
  }

  @override
  Future<SupportTicket> getTicket(String ticketId) async {
    final res = await _dio.get('/v1/customer/support/tickets/$ticketId');
    return _ticketFromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<SupportTicket> createTicket(CreateTicketRequest request) async {
    final res = await _dio.post('/v1/customer/support/tickets', data: request.toJson());
    return _ticketFromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<SupportTicket> replyToTicket(String ticketId, String message) async {
    final res = await _dio.post('/v1/customer/support/tickets/$ticketId/messages',
        data: {'message': message});
    return _ticketFromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<PaginatedResult<Dispute>> getDisputes(int page) async {
    final res = await _dio.get('/v1/customer/disputes', queryParameters: {'page': page});
    return PaginatedResult.fromJson(res.data as Map<String, dynamic>, _disputeFromJson);
  }

  @override
  Future<Dispute> getDispute(String disputeId) async {
    final res = await _dio.get('/v1/customer/disputes/$disputeId');
    return _disputeFromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<Dispute> raiseDispute(RaiseDisputeRequest request) async {
    final res = await _dio.post('/v1/customer/disputes', data: request.toJson());
    return _disputeFromJson(res.data as Map<String, dynamic>);
  }

  SupportTicket _ticketFromJson(Map<String, dynamic> j) => SupportTicket(
        id: j['id'] as String,
        referenceNumber: j['reference_number'] as String,
        subject: j['subject'] as String,
        category: j['category'] as String,
        status: TicketStatus.values.firstWhere(
          (e) => e.name == (j['status'] as String? ?? 'open'),
          orElse: () => TicketStatus.open,
        ),
        priority: TicketPriority.values.firstWhere(
          (e) => e.name == (j['priority'] as String? ?? 'medium'),
          orElse: () => TicketPriority.medium,
        ),
        messages: (j['messages'] as List? ?? [])
            .map((m) => _messageFromJson(m as Map<String, dynamic>))
            .toList(),
        createdAt: DateTime.parse(j['created_at'] as String),
        updatedAt: DateTime.parse(j['updated_at'] as String),
      );

  TicketMessage _messageFromJson(Map<String, dynamic> j) => TicketMessage(
        id: j['id'] as String,
        body: j['body'] as String,
        isFromSupport: j['is_from_support'] as bool? ?? false,
        createdAt: DateTime.parse(j['created_at'] as String),
      );

  Dispute _disputeFromJson(Map<String, dynamic> j) => Dispute(
        id: j['id'] as String,
        referenceNumber: j['reference_number'] as String,
        transactionReference: j['transaction_reference'] as String,
        reason: j['reason'] as String,
        status: DisputeStatus.values.firstWhere(
          (e) => e.name == (j['status'] as String? ?? 'open'),
          orElse: () => DisputeStatus.open,
        ),
        disputedAmountMinorUnits: j['disputed_amount_minor_units'] as int,
        currencyCode: j['currency_code'] as String,
        createdAt: DateTime.parse(j['created_at'] as String),
      );
}

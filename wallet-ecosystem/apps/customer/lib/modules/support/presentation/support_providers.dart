import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/support_ticket.dart';
import '../domain/repositories/support_repository.dart';
import '../data/repositories/support_repository_impl.dart';
import '../../../../core/api/paginated_result.dart';
import '../../../core/di/providers.dart';

final supportRepositoryProvider = Provider<SupportRepository>((ref) {
  return SupportRepositoryImpl(apiClient: ref.watch(apiClientProvider));
});

final ticketsProvider =
    FutureProvider.autoDispose.family<PaginatedResult<SupportTicket>, int>((ref, page) async {
  return ref.watch(supportRepositoryProvider).getTickets(page);
});

final ticketDetailProvider =
    FutureProvider.autoDispose.family<SupportTicket, String>((ref, id) async {
  return ref.watch(supportRepositoryProvider).getTicket(id);
});

final disputesProvider =
    FutureProvider.autoDispose.family<PaginatedResult<Dispute>, int>((ref, page) async {
  return ref.watch(supportRepositoryProvider).getDisputes(page);
});

final disputeDetailProvider =
    FutureProvider.autoDispose.family<Dispute, String>((ref, id) async {
  return ref.watch(supportRepositoryProvider).getDispute(id);
});

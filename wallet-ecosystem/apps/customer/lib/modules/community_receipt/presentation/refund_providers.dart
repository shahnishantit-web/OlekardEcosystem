import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../domain/entities/refund_status.dart';

/// Fetches refund requests for a given order, plus eligibility and policy text.
final refundStatusProvider = FutureProvider.autoDispose
    .family<RefundState, String>((ref, orderId) async {
  // TODO: inject HTTP client and call GET /v1/community/orders/{orderId}/refunds
  await Future<void>.delayed(const Duration(milliseconds: 300));
  return const RefundState(
    requests: [],
    isEligible: true,
    policyText:
        'Refunds are available within 30 days of purchase for cancelled '
        'services. Consumed tickets and awarded raffle entries are non-refundable.',
  );
});

/// Notifier for submitting a new refund request.
final refundSubmitProvider = AsyncNotifierProvider.autoDispose
    .family<RefundSubmitNotifier, void, String>(RefundSubmitNotifier.new);

class RefundSubmitNotifier extends AsyncNotifier<void> {
  final String orderId;
  RefundSubmitNotifier(this.orderId);

  @override
  Future<void> build() async {}

  Future<void> request({required String reason}) async {
    state = const AsyncLoading();
    // TODO: inject HTTP client and POST to /v1/community/orders/{orderId}/refunds
    await Future<void>.delayed(const Duration(milliseconds: 500));
    ref.invalidate(refundStatusProvider(orderId));
    state = const AsyncData(null);
  }
}

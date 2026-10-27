import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_payment/shared_payment.dart';
import '../../../../core/di/providers.dart';

class FuneralObligationItem {
  final String id;
  final String collectionId;
  final int amountMinor;
  final String currencyCode;
  final String status;
  final String fulfilmentStatus;

  const FuneralObligationItem({
    required this.id,
    required this.collectionId,
    required this.amountMinor,
    required this.currencyCode,
    required this.status,
    required this.fulfilmentStatus,
  });

  factory FuneralObligationItem.fromJson(Map<String, dynamic> json) =>
      FuneralObligationItem(
        id: json['id'] as String? ?? '',
        collectionId: json['collection_id'] as String? ?? '',
        amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 2000,
        currencyCode: json['currency_code'] as String? ?? 'BWP',
        status: json['status'] as String? ?? 'unpaid',
        fulfilmentStatus: json['fulfilment_status'] as String? ?? 'pending',
      );

  bool get isPayable => status == 'unpaid' && fulfilmentStatus == 'pending';
  bool get isFulfilled => fulfilmentStatus == 'fulfilled' || status == 'confirmed';
}

final funeralObligationsProvider =
    FutureProvider.autoDispose<List<FuneralObligationItem>>((ref) async {
  final dio = ref.watch(apiClientProvider).dio;
  try {
    final response = await dio.get('/api/v1/community/funeral/my/obligations');
    final data = response.data['data'] as List<dynamic>? ?? [];
    return data
        .map((item) => FuneralObligationItem.fromJson(item as Map<String, dynamic>))
        .toList();
  } catch (e) {
    // If the readiness gate is closed (503) or no network, return empty list
    return [];
  }
});

class FuneralAssistanceScreen extends ConsumerWidget {
  const FuneralAssistanceScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asyncObligations = ref.watch(funeralObligationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Funeral Assistance')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(funeralObligationsProvider.future),
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              elevation: 0,
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              child: const Padding(
                padding: EdgeInsets.all(16),
                child: Row(
                  children: [
                    Icon(Icons.volunteer_activism_outlined, size: 32),
                    SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Community Support',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                          SizedBox(height: 4),
                          Text(
                            'Standard household contribution for verified community funeral collections.',
                            style: TextStyle(fontSize: 13),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),
            asyncObligations.when(
              loading: () => const Center(
                child: Padding(
                  padding: EdgeInsets.all(40),
                  child: CircularProgressIndicator(),
                ),
              ),
              error: (err, _) => Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text('Unable to load obligations: $err'),
                ),
              ),
              data: (obligations) {
                if (obligations.isEmpty) {
                  return const Center(
                    child: Padding(
                      padding: EdgeInsets.symmetric(vertical: 40, horizontal: 16),
                      child: Column(
                        children: [
                          Icon(Icons.check_circle_outline, size: 48, color: Colors.grey),
                          SizedBox(height: 16),
                          Text(
                            'No Outstanding Contributions',
                            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
                          ),
                          SizedBox(height: 8),
                          Text(
                            'Your household currently has no pending funeral assistance obligations.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.grey),
                          ),
                        ],
                      ),
                    ),
                  );
                }

                return Column(
                  children: obligations.map((item) => _ObligationCard(item: item)).toList(),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _ObligationCard extends StatelessWidget {
  final FuneralObligationItem item;

  const _ObligationCard({required this.item});

  @override
  Widget build(BuildContext context) {
    final amountFormatted =
        '${item.currencyCode} ${(item.amountMinor / 100).toStringAsFixed(2)}';

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Funeral Collection',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                _StatusChip(status: item.status, fulfilment: item.fulfilmentStatus),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              amountFormatted,
              style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            Text(
              'Obligation Ref: ${item.id.length > 8 ? item.id.substring(0, 8) : item.id}',
              style: TextStyle(fontSize: 12, color: Colors.grey[600]),
            ),
            const SizedBox(height: 16),
            if (item.isPayable)
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  icon: const Icon(Icons.payment),
                  label: Text('Contribute $amountFormatted'),
                  onPressed: () {
                    showPaymentCheckout(
                      context: context,
                      purposeType: 'funeral_contribution',
                      purposeReference: item.id,
                      currencyCode: item.currencyCode,
                      estimatedAmountMinor: item.amountMinor,
                      returnRoute: '/services/funeral-assistance',
                    );
                  },
                ),
              )
            else if (item.isFulfilled)
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.receipt_long),
                  label: const Text('View Payment History & Receipt'),
                  onPressed: () {
                    context.push('/transactions');
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  final String status;
  final String fulfilment;

  const _StatusChip({required this.status, required this.fulfilment});

  @override
  Widget build(BuildContext context) {
    Color bg = Colors.grey.shade200;
    Color fg = Colors.grey.shade800;
    String label = 'Unknown';

    if (fulfilment == 'fulfilled' || status == 'confirmed') {
      bg = Colors.green.shade100;
      fg = Colors.green.shade800;
      label = 'Fulfilled';
    } else if (status == 'unpaid') {
      bg = Colors.amber.shade100;
      fg = Colors.amber.shade900;
      label = 'Payment Required';
    } else if (status == 'late_review' || fulfilment == 'held_for_review') {
      bg = Colors.orange.shade100;
      fg = Colors.orange.shade900;
      label = 'Under Review';
    } else if (status == 'pending') {
      bg = Colors.blue.shade100;
      fg = Colors.blue.shade900;
      label = 'Processing';
    }

    return Chip(
      label: Text(label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: fg)),
      backgroundColor: bg,
      padding: EdgeInsets.zero,
      visualDensity: VisualDensity.compact,
    );
  }
}

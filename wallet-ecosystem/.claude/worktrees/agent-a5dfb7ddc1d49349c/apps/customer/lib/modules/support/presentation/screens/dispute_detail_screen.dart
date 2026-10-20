import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class DisputeDetailScreen extends ConsumerWidget {
  final String disputeId;
  const DisputeDetailScreen({super.key, required this.disputeId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final disputeAsync = ref.watch(disputeDetailProvider(disputeId));

    return Scaffold(
      appBar: AppBar(title: const Text('Dispute Details')),
      body: disputeAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 5),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (dispute) {
          final statusColor = switch (dispute.status) {
            DisputeStatus.open => Colors.blue,
            DisputeStatus.underReview => Colors.orange,
            DisputeStatus.resolved => Colors.green,
            DisputeStatus.closed => Colors.grey,
          };

          return SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text('Dispute ${dispute.referenceNumber}',
                          style: AppTypography.headline),
                    ),
                    Chip(
                      label: Text(dispute.status.name),
                      backgroundColor: statusColor.withValues(alpha: 0.15),
                      side: BorderSide(color: statusColor),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                _Row('Transaction Ref', Text(dispute.transactionReference, style: AppTypography.body)),
                _Row(
                  'Disputed Amount',
                  AmountDisplay(
                    minorUnits: dispute.disputedAmountMinorUnits,
                    currencyCode: dispute.currencyCode,
                  ),
                ),
                _Row(
                  'Filed On',
                  Text(
                    dispute.createdAt.toLocal().toString().substring(0, 10),
                    style: AppTypography.body,
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                const Text('Reason', style: AppTypography.label),
                const SizedBox(height: AppSpacing.sm),
                Container(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(dispute.reason, style: AppTypography.body),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final Widget value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: AppTypography.label),
            value,
          ],
        ),
      );
}

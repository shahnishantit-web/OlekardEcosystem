import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/receipt/receipt_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

class TransactionDetailScreen extends ConsumerWidget {
  final String transactionId;
  const TransactionDetailScreen({super.key, required this.transactionId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final txAsync = ref.watch(transactionDetailProvider(transactionId));

    return Scaffold(
      appBar: AppBar(title: const Text('Transaction Detail')),
      body: txAsync.when(
        loading: () =>
            const Padding(padding: EdgeInsets.all(16), child: ShimmerCard()),
        error: (e, _) => ErrorView(
          message: 'Failed to load transaction.',
          onRetry: () =>
              ref.invalidate(transactionDetailProvider(transactionId)),
        ),
        data: (tx) => SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    children: [
                      _Row(
                          label: 'Reference',
                          value: tx.referenceNumber),
                      _Row(label: 'Counterparty', value: tx.counterpartyName),
                      _Row(
                        label: 'Date',
                        value: DateFormat('d MMM yyyy, HH:mm')
                            .format(tx.createdAt),
                      ),
                      _Row(label: 'Status', value: tx.status.toUpperCase()),
                      const Divider(),
                      Padding(
                        padding: const EdgeInsets.symmetric(
                            vertical: AppSpacing.sm),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text('Amount',
                                style: AppTypography.titleMedium),
                            AmountDisplay(
                              minorUnits: tx.amountMinorUnits,
                              currencyCode: tx.currencyCode,
                              size: AmountSize.large,
                            ),
                          ],
                        ),
                      ),
                      if (tx.feeMinorUnits > 0)
                        Padding(
                          padding: const EdgeInsets.symmetric(
                              vertical: AppSpacing.xs),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text('Fee',
                                  style: AppTypography.bodyMedium.copyWith(
                                      color: AppColors.onSurfaceVariant)),
                              AmountDisplay(
                                minorUnits: tx.feeMinorUnits,
                                currencyCode: tx.currencyCode,
                                size: AmountSize.small,
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              OutlinedButton.icon(
                icon: const Icon(Icons.share),
                label: const Text('Share Receipt'),
                onPressed: () {
                  final data = ReceiptData(
                    type: tx.type.name,
                    referenceNumber: tx.referenceNumber,
                    amountMinorUnits: tx.amountMinorUnits,
                    feeMinorUnits: tx.feeMinorUnits,
                    netMinorUnits: tx.netMinorUnits,
                    currencyCode: tx.currencyCode,
                    customerName: tx.counterpartyName,
                    timestamp: tx.createdAt,
                  );
                  ReceiptService.shareReceipt(data);
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row({required this.label, required this.value});
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant)),
          Flexible(
            child: Text(value,
                style: AppTypography.bodyMedium,
                textAlign: TextAlign.end),
          ),
        ],
      ),
    );
  }
}

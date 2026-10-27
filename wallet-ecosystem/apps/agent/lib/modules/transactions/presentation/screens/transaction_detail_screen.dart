import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/receipt/receipt_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';
import 'package:wallet_agent/modules/transactions/application/payment_detail_notifier.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_status_rows.dart';
import 'package:wallet_agent/modules/transactions/presentation/widgets/payment_status_row.dart';
import 'package:wallet_agent/modules/transactions/presentation/widgets/domain_deep_link_tile.dart';
import 'package:wallet_agent/modules/transactions/presentation/screens/support_ticket_sheet.dart';
import 'package:wallet_agent/core/router/payment_routes.dart';

class TransactionDetailScreen extends ConsumerWidget {
  final String transactionId;
  const TransactionDetailScreen({super.key, required this.transactionId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detailAsync = ref.watch(paymentDetailProvider(transactionId));

    return Scaffold(
      appBar: AppBar(title: const Text('Transaction Detail')),
      body: detailAsync.when(
        loading: () => Semantics(
          liveRegion: true,
          label: 'Loading...',
          child: const Padding(padding: EdgeInsets.all(16), child: ShimmerCard()),
        ),
        error: (e, _) => ErrorView(
          message: 'Failed to load transaction.',
          onRetry: () =>
              ref.invalidate(paymentDetailProvider(transactionId)),
        ),
        data: (state) => _buildBody(context, ref, state),
      ),
    );
  }

  Widget _buildBody(
      BuildContext context, WidgetRef ref, PaymentDetailState state) {
    if (state is PaymentDetailLoaded) {
      final tx = state.item;
      return SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(
                  children: [
                    _Row(label: 'Reference', value: tx.referenceNumber),
                    if (tx.counterpartyName != null)
                      _Row(label: 'Counterparty', value: tx.counterpartyName!),
                    _Row(
                      label: 'Date',
                      value: DateFormat('d MMM yyyy, HH:mm').format(tx.createdAt),
                    ),
                    _Row(label: 'Status', value: tx.status.name),
                    PaymentStatusRow(label: 'Capture', value: state.statusRows.captureStatus),
                    PaymentStatusRow(label: 'Accounting', value: state.statusRows.accountingStatus),
                    PaymentStatusRow(label: 'Service', value: state.statusRows.serviceStatus),
                    if (state.statusRows.refundStatus != null)
                      PaymentStatusRow(label: 'Refund', value: state.statusRows.refundStatus!),
                    const Divider(),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Amount', style: AppTypography.titleMedium),
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
                        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
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
            if (tx.domainDeepLink != null) ...[
              const SizedBox(height: AppSpacing.md),
              DomainDeepLinkTile(deepLink: tx.domainDeepLink!),
            ],
            const SizedBox(height: AppSpacing.lg),
            if (tx.receiptAvailable)
              SizedBox(
                height: 44,
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.receipt_outlined),
                  label: const Text('View Receipt'),
                  onPressed: () => context.push(
                      PaymentRoutes.receiptRoute(transactionId)),
                ),
              ),
            const SizedBox(height: AppSpacing.sm),
            if (tx.refundEligible)
              SizedBox(
                height: 44,
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.replay_outlined),
                  label: const Text('Request Refund'),
                  onPressed: () => ref
                      .read(paymentDetailProvider(transactionId).notifier)
                      .initiateRefund(),
                ),
              ),
            const SizedBox(height: AppSpacing.sm),
            SizedBox(
              height: 44,
              child: OutlinedButton.icon(
                icon: const Icon(Icons.support_agent_outlined),
                label: const Text('Get Support'),
                onPressed: () => ref
                    .read(paymentDetailProvider(transactionId).notifier)
                    .openSupportDraft(),
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            OutlinedButton.icon(
              icon: const Icon(Icons.share),
              label: const Text('Share Receipt'),
              onPressed: () {
                final data = ReceiptData(
                  type: tx.purposeType.name,
                  referenceNumber: tx.referenceNumber,
                  amountMinorUnits: tx.amountMinorUnits,
                  feeMinorUnits: tx.feeMinorUnits,
                  netMinorUnits: tx.amountMinorUnits - tx.feeMinorUnits,
                  currencyCode: tx.currencyCode,
                  customerName: tx.counterpartyName ?? '',
                  timestamp: tx.createdAt,
                );
                ReceiptService.shareReceipt(data);
              },
            ),
          ],
        ),
      );
    }
    if (state is PaymentDetailSupportDraft) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          builder: (_) => SupportTicketDraftSheet(
            paymentIntentId: state.item.id,
            prefilledSubject: state.item.displayTitle,
          ),
        ).then((_) {
          ref.read(paymentDetailProvider(transactionId).notifier).cancel();
        });
      });
      return _buildBody(context, ref,
          PaymentDetailLoaded(
            item: state.item,
            statusRows: PaymentStatusRows.fromItem(state.item),
            deepLink: state.item.domainDeepLink,
          ));
    }
    if (state is PaymentDetailError) {
      return ErrorView(message: state.message);
    }
    return const Center(child: CircularProgressIndicator());
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
                style: AppTypography.bodyMedium, textAlign: TextAlign.end),
          ),
        ],
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/float/presentation/float_providers.dart';

class FloatHistoryScreen extends ConsumerWidget {
  const FloatHistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final historyAsync = ref.watch(floatTransactionsProvider);
    final filter = ref.watch(floatHistoryFilterProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Float History'),
        actions: [
          Semantics(
            label: filter.hasRange
                ? 'Clear date range filter'
                : 'Filter by date range',
            button: true,
            child: IconButton(
              icon: Icon(
                Icons.date_range,
                color: filter.hasRange ? AppColors.primary : null,
              ),
              tooltip: filter.hasRange ? 'Clear date filter' : 'Date range',
              onPressed: filter.hasRange
                  ? () => ref
                      .read(floatHistoryFilterProvider.notifier)
                      .state = const FloatHistoryFilter()
                  : () async {
                      final range = await showDateRangePicker(
                        context: context,
                        firstDate: DateTime(2020),
                        lastDate: DateTime.now(),
                      );
                      if (range != null && context.mounted) {
                        ref.read(floatHistoryFilterProvider.notifier).state =
                            FloatHistoryFilter(
                          fromDate: range.start,
                          toDate: range.end,
                        );
                      }
                    },
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          if (filter.hasRange)
            Padding(
              padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md, vertical: AppSpacing.xs),
              child: Row(
                children: [
                  const Icon(Icons.filter_list,
                      size: 16, color: AppColors.onSurfaceVariant),
                  const SizedBox(width: AppSpacing.xs),
                  Text(
                    '${DateFormat('d MMM').format(filter.fromDate!)} – ${DateFormat('d MMM yyyy').format(filter.toDate!)}',
                    style: AppTypography.bodySmall
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          Expanded(
            child: historyAsync.when(
              loading: () => const ShimmerList(itemCount: 8),
              error: (e, _) => ErrorView(
                message: 'Failed to load float history.',
                onRetry: () => ref.invalidate(floatTransactionsProvider),
              ),
              data: (page) => page.data.isEmpty
                  ? const Center(child: Text('No float history found.'))
                  : ListView.builder(
                      itemCount: page.data.length,
                      itemBuilder: (ctx, i) {
                        final tx = page.data[i];
                        final isCredit =
                            tx.type.contains('top_up') ||
                                tx.type.contains('credit') ||
                                tx.type == 'cash_out';
                        final typeLabel = _labelForType(tx.type);
                        return Semantics(
                          label:
                              '$typeLabel, ${tx.amountMinorUnits ~/ 100} ${tx.currencyCode}',
                          child: ListTile(
                            leading: CircleAvatar(
                              backgroundColor: isCredit
                                  ? AppColors.successLight
                                  : AppColors.errorLight,
                              child: Icon(
                                isCredit
                                    ? Icons.arrow_downward
                                    : Icons.arrow_upward,
                                color: isCredit
                                    ? AppColors.success
                                    : AppColors.error,
                              ),
                            ),
                            title: Text(typeLabel),
                            subtitle: Text(
                              '${tx.referenceNumber} · ${DateFormat('d MMM HH:mm').format(tx.createdAt)}',
                              style: AppTypography.bodySmall.copyWith(
                                  color: AppColors.onSurfaceVariant),
                            ),
                            trailing: AmountDisplay(
                              minorUnits: tx.amountMinorUnits,
                              currencyCode: tx.currencyCode,
                              size: AmountSize.small,
                              color: isCredit ? AppColors.success : null,
                            ),
                          ),
                        );
                      },
                    ),
            ),
          ),
        ],
      ),
    );
  }

  String _labelForType(String type) {
    return switch (type) {
      'top_up' || 'float_top_up' => 'Float Top-Up',
      'cash_in_credit' || 'cash_in' => 'Cash-In Credit',
      'cash_out_debit' || 'cash_out' => 'Cash-Out Debit',
      _ => type.replaceAll('_', ' ').toUpperCase(),
    };
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/commissions/presentation/commission_providers.dart';

const _types = ['cash_in', 'cash_out', 'registration'];

class CommissionsScreen extends ConsumerWidget {
  const CommissionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balanceAsync = ref.watch(commissionBalanceProvider);
    final entriesAsync = ref.watch(commissionEntriesProvider);
    final filter = ref.watch(commissionFilterProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Commissions')),
      body: Column(
        children: [
          const OfflineBanner(),
          balanceAsync.when(
            loading: () => const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: ShimmerCard(),
            ),
            error: (e, _) => Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: ErrorView(
                message: 'Failed to load commission balance.',
                onRetry: () => ref.invalidate(commissionBalanceProvider),
              ),
            ),
            data: (balance) => Card(
              margin: const EdgeInsets.all(AppSpacing.md),
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Available', style: AppTypography.labelLarge),
                        Semantics(
                          label:
                              'Available commission: ${balance.availableMinorUnits ~/ 100} ${balance.currencyCode}',
                          child: AmountDisplay(
                            minorUnits: balance.availableMinorUnits,
                            currencyCode: balance.currencyCode,
                            size: AmountSize.large,
                          ),
                        ),
                      ],
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        const Text('Pending', style: AppTypography.labelLarge),
                        AmountDisplay(
                          minorUnits: balance.pendingMinorUnits,
                          currencyCode: balance.currencyCode,
                          size: AmountSize.medium,
                          color: AppColors.onSurfaceVariant,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
          // Type filter chips
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              children: [
                _FilterChip(
                  label: 'All',
                  selected: filter.type == null,
                  onSelected: () => ref
                      .read(commissionFilterProvider.notifier)
                      .state = filter.copyWith(type: null),
                ),
                ..._types.map(
                  (t) => _FilterChip(
                    label: t.replaceAll('_', ' ').toUpperCase(),
                    selected: filter.type == t,
                    onSelected: () => ref
                        .read(commissionFilterProvider.notifier)
                        .state = filter.copyWith(type: t),
                  ),
                ),
                _DateRangeButton(
                  filter: filter,
                  onChanged: (from, to) => ref
                      .read(commissionFilterProvider.notifier)
                      .state = filter.copyWith(fromDate: from, toDate: to),
                ),
              ],
            ),
          ),
          Expanded(
            child: entriesAsync.when(
              loading: () => const ShimmerList(itemCount: 6),
              error: (e, _) => ErrorView(
                message: 'Failed to load commission entries.',
                onRetry: () => ref.invalidate(commissionEntriesProvider),
              ),
              data: (page) => page.data.isEmpty
                  ? const Center(child: Text('No commission entries found.'))
                  : ListView.builder(
                      itemCount: page.data.length,
                      itemBuilder: (ctx, i) {
                        final entry = page.data[i];
                        return Semantics(
                          label:
                              'Commission for ${entry.transactionType}, ${entry.amountMinorUnits ~/ 100} ${entry.currencyCode}, rate ${(entry.rateApplied * 100).toStringAsFixed(2)}%',
                          child: ListTile(
                            leading: const CircleAvatar(
                              backgroundColor: AppColors.successLight,
                              child: Icon(Icons.monetization_on_outlined,
                                  color: AppColors.success),
                            ),
                            title: Text(entry.transactionType
                                .replaceAll('_', ' ')
                                .toUpperCase()),
                            subtitle: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${entry.referenceNumber} · Rate: ${(entry.rateApplied * 100).toStringAsFixed(2)}%',
                                  style: AppTypography.bodySmall.copyWith(
                                      color: AppColors.onSurfaceVariant),
                                ),
                                Text(
                                  DateFormat('d MMM yyyy')
                                      .format(entry.createdAt),
                                  style: AppTypography.bodySmall.copyWith(
                                      color: AppColors.onSurfaceVariant),
                                ),
                              ],
                            ),
                            isThreeLine: true,
                            trailing: AmountDisplay(
                              minorUnits: entry.amountMinorUnits,
                              currencyCode: entry.currencyCode,
                              size: AmountSize.small,
                              color: AppColors.success,
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
}

class _FilterChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onSelected;

  const _FilterChip({
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: AppSpacing.sm),
      child: FilterChip(
        label: Text(label, style: AppTypography.labelSmall),
        selected: selected,
        onSelected: (_) => onSelected(),
        selectedColor: AppColors.primaryLight,
      ),
    );
  }
}

class _DateRangeButton extends StatelessWidget {
  final CommissionFilter filter;
  final void Function(DateTime? from, DateTime? to) onChanged;

  const _DateRangeButton({required this.filter, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final hasRange = filter.fromDate != null || filter.toDate != null;
    return ActionChip(
      avatar: Icon(
        Icons.date_range,
        size: 16,
        color: hasRange ? AppColors.primary : AppColors.onSurfaceVariant,
      ),
      label: Text(
        hasRange ? 'Date Range ×' : 'Date Range',
        style: AppTypography.labelSmall.copyWith(
          color: hasRange ? AppColors.primary : null,
        ),
      ),
      onPressed: hasRange
          ? () => onChanged(null, null)
          : () async {
              final range = await showDateRangePicker(
                context: context,
                firstDate: DateTime(2020),
                lastDate: DateTime.now(),
              );
              if (range != null) {
                onChanged(range.start, range.end);
              }
            },
    );
  }
}

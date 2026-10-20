import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';
import 'package:wallet_agent/modules/float/presentation/float_providers.dart';

class FloatScreen extends ConsumerWidget {
  const FloatScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final balanceAsync = ref.watch(floatBalanceProvider);
    final txAsync = ref.watch(floatTransactionsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Float'),
        actions: [
          Semantics(
            label: 'Request Float Top-Up',
            child: IconButton(
              icon: const Icon(Icons.add_circle_outline),
              tooltip: 'Request Top-Up',
              onPressed: () => context.push('/float/top-up'),
            ),
          ),
        ],
      ),
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
                message: 'Failed to load balance.',
                onRetry: () => ref.invalidate(floatBalanceProvider),
              ),
            ),
            data: (balance) => _BalanceCard(balance: balance),
          ),
          Expanded(
            child: txAsync.when(
              loading: () => const ShimmerList(itemCount: 6),
              error: (e, _) => ErrorView(
                message: 'Failed to load transactions.',
                onRetry: () => ref.invalidate(floatTransactionsProvider),
              ),
              data: (page) => page.data.isEmpty
                  ? const Center(child: Text('No float transactions yet.'))
                  : ListView.builder(
                      itemCount: page.data.length,
                      itemBuilder: (ctx, i) {
                        final tx = page.data[i];
                        return Semantics(
                          label:
                              '${tx.type}, ${tx.amountMinorUnits ~/ 100} ${tx.currencyCode}',
                          child: ListTile(
                            leading: const Icon(Icons.swap_horiz),
                            title: Text(tx.type
                                .replaceAll('_', ' ')
                                .toUpperCase()),
                            subtitle: Text(
                              DateFormat('d MMM yyyy, HH:mm')
                                  .format(tx.createdAt),
                              style: AppTypography.bodySmall.copyWith(
                                  color: AppColors.onSurfaceVariant),
                            ),
                            trailing: AmountDisplay(
                              minorUnits: tx.amountMinorUnits,
                              currencyCode: tx.currencyCode,
                              size: AmountSize.small,
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

class _BalanceCard extends StatelessWidget {
  final FloatBalance balance;
  const _BalanceCard({required this.balance});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.all(AppSpacing.md),
      color: balance.isLow ? AppColors.errorLight : AppColors.surface,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Float Balance', style: AppTypography.labelLarge),
            const SizedBox(height: AppSpacing.sm),
            Semantics(
              label:
                  'Float balance: ${balance.balanceMinorUnits ~/ 100} ${balance.currencyCode}',
              child: AmountDisplay(
                minorUnits: balance.balanceMinorUnits,
                currencyCode: balance.currencyCode,
                size: AmountSize.large,
              ),
            ),
            if (balance.isLow) ...[
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  const Icon(Icons.warning_amber, color: AppColors.error, size: 16),
                  const SizedBox(width: AppSpacing.xs),
                  Text(
                    'Float balance is low',
                    style: AppTypography.bodySmall
                        .copyWith(color: AppColors.error),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/modules/float/presentation/float_providers.dart';

enum _FloatLevel { normal, low, critical }

class FloatStatusCard extends ConsumerWidget {
  const FloatStatusCard({super.key});

  _FloatLevel _level(bool isLow, int balance) {
    if (!isLow) return _FloatLevel.normal;
    // Critical: less than half the low threshold
    return _FloatLevel.low;
  }

  ({Color bg, Color fg, String label}) _styling(
    BuildContext context,
    _FloatLevel level,
  ) {
    switch (level) {
      case _FloatLevel.normal:
        return (
          bg: AppColors.success.withValues(alpha: 0.1),
          fg: AppColors.success,
          label: 'Float OK',
        );
      case _FloatLevel.low:
        return (
          bg: AppColors.warning.withValues(alpha: 0.1),
          fg: AppColors.warning,
          label: 'Low Float',
        );
      case _FloatLevel.critical:
        return (
          bg: AppColors.error.withValues(alpha: 0.1),
          fg: AppColors.error,
          label: 'Critical',
        );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final floatAsync = ref.watch(floatBalanceProvider);

    return floatAsync.when(
      loading: () => const ShimmerBox(width: double.infinity, height: 96),
      error: (e, _) => Card(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              const Icon(Icons.error_outline, color: AppColors.error),
              const SizedBox(width: AppSpacing.sm),
              const Expanded(
                child: Text(
                  'Failed to load float balance',
                  style: TextStyle(color: AppColors.error),
                ),
              ),
              TextButton(
                onPressed: () => ref.invalidate(floatBalanceProvider),
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      ),
      data: (balance) {
        final level = _level(balance.isLow, balance.balanceMinorUnits);
        final style = _styling(context, level);

        return Card(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Float Balance',
                      style: AppTypography.labelLarge.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                    // Status chip — communicates via BOTH color AND text (FR-017)
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: AppSpacing.sm,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: style.bg,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: style.fg,
                            ),
                          ),
                          const SizedBox(width: 4),
                          Text(
                            style.label,
                            style: AppTypography.bodySmall.copyWith(
                              color: style.fg,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                AmountDisplay(
                  minorUnits: balance.balanceMinorUnits,
                  currencyCode: balance.currencyCode,
                  size: AmountSize.large,
                  color: AppColors.textPrimary,
                ),
                const SizedBox(height: AppSpacing.sm),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: () => context.go('/float'),
                    child: const Text('View Float'),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

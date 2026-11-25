import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../home_providers.dart';
import '../../../wallet/domain/entities/wallet.dart';

class WalletSummaryCard extends ConsumerWidget {
  final Wallet wallet;

  const WalletSummaryCard({super.key, required this.wallet});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final visible = ref.watch(balanceVisibleProvider);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.textPrimary,
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(
          colors: [AppColors.textPrimary, Color(0xFF172C55)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Padding(
        padding: EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  wallet.currencyCode,
                  style: AppTypography.label.copyWith(color: Colors.white70),
                ),
                // 44dp minimum touch target
                SizedBox(
                  width: 44,
                  height: 44,
                  child: IconButton(
                    padding: EdgeInsets.zero,
                    icon: Icon(
                      visible
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                      color: Colors.white,
                    ),
                    tooltip: visible ? 'Hide balance' : 'Show balance',
                    onPressed: () => ref
                        .read(balanceVisibleProvider.notifier)
                        .state = !visible,
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            if (visible)
              AmountDisplay(
                amountMinorUnits: wallet.balanceMinorUnits,
                currencyCode: wallet.currencyCode,
                style: AppTypography.amount.copyWith(color: Colors.white),
              )
            else
              Text(
                '••••••',
                style: AppTypography.amount.copyWith(color: Colors.white),
              ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'Available balance',
              style: AppTypography.caption.copyWith(color: Colors.white70),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              'Updated ${_formatTime(wallet.updatedAt)}',
              style: AppTypography.caption.copyWith(color: Colors.white70),
            ),
          ],
        ),
      ),
    );
  }

  String _formatTime(DateTime dt) {
    final now = DateTime.now();
    final diff = now.difference(dt);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    return '${dt.day}/${dt.month}';
  }
}

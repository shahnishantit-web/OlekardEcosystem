import 'package:flutter/material.dart';

import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../domain/entities/wallet.dart';

class WalletBalanceCard extends StatelessWidget {
  const WalletBalanceCard({super.key, required this.wallet});

  final Wallet wallet;

  @override
  Widget build(BuildContext context) {
    final status = _walletStatus(wallet.status);

    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: const Color(0xFF0F172A),
        borderRadius: BorderRadius.circular(24),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF172554), Color(0xFF0F172A)],
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0F172A).withValues(alpha: .12),
            blurRadius: 22,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_wallet_outlined,
                  color: Color(0xFFBFDBFE), size: 20),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  'Available balance',
                  style: AppTypography.label.copyWith(color: Colors.white70),
                ),
              ),
              _StatusChip(label: status.label, color: status.color),
            ],
          ),
          const SizedBox(height: 18),
          AmountDisplay(
            amountMinorUnits: wallet.balanceMinorUnits,
            currencyCode: wallet.currencyCode,
            style: AppTypography.amount.copyWith(
              color: Colors.white,
              fontSize: 36,
              letterSpacing: -1,
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              const Icon(Icons.schedule_rounded,
                  size: 14, color: Colors.white54),
              const SizedBox(width: 6),
              Text(
                'Updated ${_formatUpdatedAt(wallet.updatedAt)}',
                style: AppTypography.caption.copyWith(color: Colors.white60),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static ({String label, Color color}) _walletStatus(WalletStatus value) =>
      switch (value) {
        WalletStatus.active => (
            label: 'Active',
            color: const Color(0xFF86EFAC)
          ),
        WalletStatus.pending => (
            label: 'Pending',
            color: const Color(0xFFFCD34D)
          ),
        WalletStatus.frozen => (
            label: 'Frozen',
            color: const Color(0xFFFCA5A5)
          ),
        WalletStatus.suspended => (
            label: 'Suspended',
            color: const Color(0xFFFCA5A5)
          ),
        WalletStatus.restricted => (
            label: 'Restricted',
            color: const Color(0xFFFCD34D)
          ),
        WalletStatus.closed => (
            label: 'Closed',
            color: const Color(0xFFCBD5E1)
          ),
      };

  static String _formatUpdatedAt(DateTime value) {
    final hour = value.hour % 12 == 0 ? 12 : value.hour % 12;
    final minute = value.minute.toString().padLeft(2, '0');
    final period = value.hour < 12 ? 'AM' : 'PM';
    return '$hour:$minute $period';
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(30),
          border: Border.all(color: Colors.white.withValues(alpha: .12)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 7,
              height: 7,
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            ),
            const SizedBox(width: 6),
            Text(label,
                style: AppTypography.caption.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w600,
                )),
          ],
        ),
      );
}

class WalletSectionHeader extends StatelessWidget {
  const WalletSectionHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleLarge),
                if (subtitle != null) ...[
                  const SizedBox(height: 4),
                  Text(subtitle!, style: Theme.of(context).textTheme.bodySmall),
                ],
              ],
            ),
          ),
          if (trailing != null) trailing!,
        ],
      );
}

class WalletActionTile extends StatelessWidget {
  const WalletActionTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.primary = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool primary;

  @override
  Widget build(BuildContext context) => Material(
        color: primary ? const Color(0xFFEFF6FF) : AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Container(
            constraints: const BoxConstraints(minHeight: 116),
            padding: const EdgeInsets.all(15),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(
                color: primary ? AppColors.primaryLight : AppColors.outline,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: primary ? AppColors.primary : AppColors.primaryLight,
                    borderRadius: BorderRadius.circular(13),
                  ),
                  child: Icon(icon,
                      color: primary ? Colors.white : AppColors.primary,
                      size: 21),
                ),
                const Spacer(),
                Text(title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.label.copyWith(
                      color: AppColors.textPrimary,
                    )),
                const SizedBox(height: 2),
                Text(subtitle,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.caption.copyWith(
                      color: AppColors.textSecondary,
                    )),
              ],
            ),
          ),
        ),
      );
}

class WalletFlowHeading extends StatelessWidget {
  const WalletFlowHeading({
    super.key,
    required this.step,
    required this.title,
    required this.subtitle,
  });

  final int step;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('STEP $step OF 3',
              style: AppTypography.caption.copyWith(
                color: AppColors.primary,
                fontWeight: FontWeight.w700,
                letterSpacing: .8,
              )),
          const SizedBox(height: 8),
          Text(title, style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 6),
          Text(subtitle, style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 18),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: step / 3,
              minHeight: 5,
              backgroundColor: AppColors.primaryLight,
              color: AppColors.primary,
            ),
          ),
        ],
      );
}

class WalletSummaryPanel extends StatelessWidget {
  const WalletSummaryPanel({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.outline),
        ),
        child: Column(
          children: [
            for (var i = 0; i < children.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 15),
                child: children[i],
              ),
            ],
          ],
        ),
      );
}

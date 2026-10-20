import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../home_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';
import '../../../../shared/widgets/transaction_list_tile.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final summaryAsync = ref.watch(homeSummaryProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Home'),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications_outlined),
            onPressed: () => context.push('/home/notifications'),
            tooltip: 'Notifications',
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(homeSummaryProvider.future),
        child: summaryAsync.when(
          loading: () => const Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: ShimmerList(itemCount: 5),
          ),
          error: (e, _) => ErrorView(
            message: e.toString(),
            onRetry: () => ref.invalidate(homeSummaryProvider),
          ),
          data: (summary) => ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              // Balance Card
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Wallet Balance', style: AppTypography.label),
                      const SizedBox(height: AppSpacing.sm),
                      AmountDisplay(
                        minorUnits: summary.primaryWallet.balanceMinorUnits,
                        currencyCode: summary.primaryWallet.currencyCode,
                        style: AppTypography.amount.copyWith(color: AppColors.primary),
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        summary.primaryWallet.walletNumber,
                        style: AppTypography.caption.copyWith(color: Colors.grey),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              // Shortcuts
              Row(
                children: [
                  _Shortcut(icon: Icons.add, label: 'Add Money', onTap: () => context.push('/add-money')),
                  _Shortcut(icon: Icons.send, label: 'Send', onTap: () => context.push('/send')),
                  _Shortcut(icon: Icons.money_off, label: 'Withdraw', onTap: () => context.push('/withdraw')),
                  _Shortcut(icon: Icons.qr_code, label: 'Receive', onTap: () => context.push('/receive')),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              // Recent Transactions
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Recent Transactions', style: AppTypography.title),
                  TextButton(
                    onPressed: () => context.push('/home/transactions'),
                    child: const Text('View All'),
                  ),
                ],
              ),
              ...summary.recentTransactions.map(
                (tx) => TransactionListTile(tx: tx),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Shortcut extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  const _Shortcut({required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Semantics(
        button: true,
        label: label,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.sm),
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  decoration: BoxDecoration(
                    color: AppColors.primary.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(icon, color: AppColors.primary, size: 24),
                ),
                const SizedBox(height: AppSpacing.xs),
                Text(label, style: AppTypography.caption, textAlign: TextAlign.center),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

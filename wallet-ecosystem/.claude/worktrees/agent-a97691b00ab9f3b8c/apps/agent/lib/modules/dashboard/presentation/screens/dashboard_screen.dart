import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/notifications/presentation/notification_providers.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final dashboardAsync = ref.watch(dashboardProvider);
    final unreadCount = ref.watch(unreadCountProvider);
    final isOnline = ref.watch(connectivityProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Dashboard'),
        actions: [
          Semantics(
            label: 'Notifications',
            child: IconButton(
              icon: Badge(
                label: unreadCount.maybeWhen(
                  data: (count) => count > 0 ? Text('$count') : null,
                  orElse: () => null,
                ),
                isLabelVisible: unreadCount.maybeWhen(
                  data: (count) => count > 0,
                  orElse: () => false,
                ),
                child: const Icon(Icons.notifications_outlined),
              ),
              onPressed: () => context.push('/notifications'),
              tooltip: 'Notifications',
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async => ref.invalidate(dashboardProvider),
              child: dashboardAsync.when(
                loading: () => const ShimmerList(itemCount: 4),
                error: (e, _) => ErrorView(
                  message: 'Failed to load dashboard',
                  onRetry: () => ref.invalidate(dashboardProvider),
                ),
                data: (summary) => ListView(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  children: [
                    _FloatBalanceCard(
                      balance: summary.floatBalanceMinorUnits,
                      currency: summary.floatCurrencyCode,
                      isLow: summary.isLow,
                      updatedAt: summary.updatedAt,
                    ),
                    const SizedBox(height: AppSpacing.md),
                    _StatsRow(
                      cashInCount: summary.todayCashInCount,
                      cashInAmount: summary.todayCashInAmountMinorUnits,
                      cashOutCount: summary.todayCashOutCount,
                      cashOutAmount: summary.todayCashOutAmountMinorUnits,
                      currency: summary.floatCurrencyCode,
                    ),
                    const SizedBox(height: AppSpacing.md),
                    _ShortcutGrid(isOnline: isOnline),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FloatBalanceCard extends StatelessWidget {
  final int balance;
  final String currency;
  final bool isLow;
  final DateTime updatedAt;

  const _FloatBalanceCard({
    required this.balance,
    required this.currency,
    required this.isLow,
    required this.updatedAt,
  });

  @override
  Widget build(BuildContext context) {
    final warningColor = isLow ? AppColors.floatLowAmber : AppColors.floatNormal;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Text('Float Balance', style: AppTypography.titleMedium),
                if (isLow) ...[
                  const SizedBox(width: AppSpacing.sm),
                  Icon(Icons.warning_amber, color: warningColor, size: 18),
                  const SizedBox(width: 4),
                  Text(
                    'Low Float',
                    style: TextStyle(color: warningColor, fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ],
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            AmountDisplay(
              minorUnits: balance,
              currencyCode: currency,
              size: AmountSize.large,
              color: isLow ? warningColor : AppColors.floatNormal,
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              'Updated ${DateFormat('HH:mm').format(updatedAt.toLocal())}',
              style: AppTypography.bodySmall.copyWith(color: AppColors.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatsRow extends StatelessWidget {
  final int cashInCount;
  final int cashInAmount;
  final int cashOutCount;
  final int cashOutAmount;
  final String currency;

  const _StatsRow({
    required this.cashInCount,
    required this.cashInAmount,
    required this.cashOutCount,
    required this.cashOutAmount,
    required this.currency,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _StatCard(
            label: "Today's Cash-In",
            count: cashInCount,
            amount: cashInAmount,
            currency: currency,
            color: AppColors.success,
          ),
        ),
        const SizedBox(width: AppSpacing.md),
        Expanded(
          child: _StatCard(
            label: "Today's Cash-Out",
            count: cashOutCount,
            amount: cashOutAmount,
            currency: currency,
            color: AppColors.primary,
          ),
        ),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  final String label;
  final int count;
  final int amount;
  final String currency;
  final Color color;

  const _StatCard({
    required this.label,
    required this.count,
    required this.amount,
    required this.currency,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: AppTypography.bodySmall.copyWith(color: AppColors.onSurfaceVariant)),
            const SizedBox(height: AppSpacing.xs),
            Text('$count tx', style: AppTypography.titleMedium.copyWith(color: color)),
            AmountDisplay(
              minorUnits: amount,
              currencyCode: currency,
              size: AmountSize.small,
            ),
          ],
        ),
      ),
    );
  }
}

class _ShortcutGrid extends StatelessWidget {
  final bool isOnline;

  const _ShortcutGrid({required this.isOnline});

  @override
  Widget build(BuildContext context) {
    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: 2,
      mainAxisSpacing: AppSpacing.md,
      crossAxisSpacing: AppSpacing.md,
      childAspectRatio: 1.5,
      children: [
        _ShortcutTile(
          icon: Icons.add_circle_outline,
          label: 'Cash In',
          route: '/cash-in/search',
          color: AppColors.success,
          enabled: isOnline,
        ),
        _ShortcutTile(
          icon: Icons.remove_circle_outline,
          label: 'Cash Out',
          route: '/cash-out/scan',
          color: AppColors.primary,
          enabled: isOnline,
        ),
        const _ShortcutTile(
          icon: Icons.person_add_outlined,
          label: 'Register Customer',
          route: '/onboarding/register',
          color: AppColors.secondary,
          enabled: true,
        ),
        const _ShortcutTile(
          icon: Icons.receipt_long_outlined,
          label: 'Transactions',
          route: '/transactions',
          color: AppColors.accent,
          enabled: true,
        ),
      ],
    );
  }
}

class _ShortcutTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final String route;
  final Color color;
  final bool enabled;

  const _ShortcutTile({
    required this.icon,
    required this.label,
    required this.route,
    required this.color,
    required this.enabled,
  });

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1.0 : 0.4,
      child: Card(
        child: InkWell(
          onTap: enabled ? () => context.push(route) : null,
          borderRadius: BorderRadius.circular(AppSpacing.borderRadius),
          child: Semantics(
            label: label,
            enabled: enabled,
            button: true,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, color: enabled ? color : AppColors.disabled, size: 28),
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    label,
                    style: AppTypography.labelLarge.copyWith(
                      color: enabled ? AppColors.onSurface : AppColors.disabled,
                    ),
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

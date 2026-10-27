import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/application/payment_history_notifier.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';
import 'package:wallet_agent/modules/transactions/presentation/widgets/history_filter_bar.dart';
import 'package:wallet_agent/core/router/payment_routes.dart';

class TransactionListScreen extends ConsumerStatefulWidget {
  const TransactionListScreen({super.key});

  @override
  ConsumerState<TransactionListScreen> createState() =>
      _TransactionListScreenState();
}

class _TransactionListScreenState
    extends ConsumerState<TransactionListScreen> {
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      ref.read(paymentHistoryProvider('agent').notifier).loadNextPage();
    }
  }

  @override
  Widget build(BuildContext context) {
    final historyAsync = ref.watch(paymentHistoryProvider('agent'));

    return Scaffold(
      appBar: AppBar(title: const Text('Transactions')),
      body: Column(
        children: [
          const OfflineBanner(),
          HistoryFilterBar(
            currentFilter: const PaymentHistoryFilter(),
            onFilterChanged: (f) =>
                ref.read(paymentHistoryProvider('agent').notifier).applyFilter(f),
          ),
          Expanded(
            child: historyAsync.when(
              loading: () => Semantics(
                liveRegion: true,
                label: 'Loading...',
                child: const ShimmerList(itemCount: 8),
              ),
              error: (e, _) => ErrorView(
                message: 'Failed to load transactions.',
                onRetry: () =>
                    ref.invalidate(paymentHistoryProvider('agent')),
              ),
              data: (state) => _buildState(context, state),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildState(BuildContext context, PaymentHistoryState state) {
    if (state is PaymentHistoryError && state.staleItems != null) {
      return _ItemList(
        items: state.staleItems!,
        scrollController: _scrollController,
        staleBanner: _StaleBanner(staleAt: state.staleAt),
      );
    }
    if (state is PaymentHistoryError) {
      return ErrorView(
        message: 'Failed to load transactions.',
        onRetry: () => ref.invalidate(paymentHistoryProvider('agent')),
      );
    }
    if (state is PaymentHistoryEmpty) {
      return const Center(child: Text('No transactions yet.'));
    }
    if (state is PaymentHistoryLoaded) {
      return RefreshIndicator(
        onRefresh: () =>
            ref.read(paymentHistoryProvider('agent').notifier).refresh(),
        child: _ItemList(
          items: state.items,
          scrollController: _scrollController,
          staleBanner: state.isStale ? _StaleBanner(staleAt: state.cachedAt) : null,
        ),
      );
    }
    return const Center(child: CircularProgressIndicator());
  }
}

class _ItemList extends StatelessWidget {
  final List items;
  final ScrollController scrollController;
  final Widget? staleBanner;

  const _ItemList({
    required this.items,
    required this.scrollController,
    this.staleBanner,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (staleBanner != null) staleBanner!,
        Expanded(
          child: ListView.builder(
            controller: scrollController,
            itemCount: items.length,
            itemBuilder: (ctx, i) => _TransactionTile(tx: items[i]),
          ),
        ),
      ],
    );
  }
}

class _StaleBanner extends StatelessWidget {
  final DateTime? staleAt;
  const _StaleBanner({this.staleAt});

  @override
  Widget build(BuildContext context) {
    final label = staleAt != null
        ? 'Last updated: ${DateFormat.yMMMd().add_jm().format(staleAt!)}'
        : 'Showing cached data';
    return Semantics(
      liveRegion: true,
      child: Container(
        width: double.infinity,
        color: Colors.orange.shade100,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Text(label,
            style: AppTypography.bodySmall
                .copyWith(color: AppColors.onSurfaceVariant)),
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  final PaymentHistoryItem tx;
  const _TransactionTile({required this.tx});

  @override
  Widget build(BuildContext context) {
    final isCredit = tx.direction == PaymentDirection.credit;

    return Semantics(
      label:
          '${tx.displayTitle}, ${tx.amountMinorUnits ~/ 100} ${tx.currencyCode}, ${tx.status.name}',
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor:
              isCredit ? AppColors.successLight : AppColors.errorLight,
          child: Icon(
            isCredit ? Icons.arrow_downward : Icons.arrow_upward,
            color: isCredit ? AppColors.success : AppColors.error,
          ),
        ),
        title: Text(tx.displayTitle),
        subtitle: Text(
          '${tx.purposeType.name} · ${DateFormat('d MMM, HH:mm').format(tx.createdAt)}',
          style: AppTypography.bodySmall
              .copyWith(color: AppColors.onSurfaceVariant),
        ),
        trailing: AmountDisplay(
          minorUnits: tx.amountMinorUnits,
          currencyCode: tx.currencyCode,
          size: AmountSize.small,
          color: isCredit ? AppColors.success : null,
        ),
        onTap: () => context.push(PaymentRoutes.detailRoute(tx.id)),
      ),
    );
  }
}

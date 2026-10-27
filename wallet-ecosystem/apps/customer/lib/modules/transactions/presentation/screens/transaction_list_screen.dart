import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../transaction_providers.dart';
import '../../application/payment_history_notifier.dart';
import '../../domain/entities/payment_history_filter.dart';
import '../widgets/history_filter_bar.dart';
import '../../../../../core/theme/design_tokens.dart';
import '../../../../../shared/widgets/error_view.dart';
import '../../../../../shared/widgets/loading_shimmer.dart';
import '../../../../../shared/widgets/transaction_list_tile.dart';
import '../../../../../core/router/payment_routes.dart';

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
      final notifier = ref.read(paymentHistoryProvider('me').notifier);
      notifier.loadNextPage();
    }
  }

  @override
  Widget build(BuildContext context) {
    final historyAsync = ref.watch(paymentHistoryProvider('me'));

    return Scaffold(
      appBar: AppBar(title: const Text('Activity')),
      body: historyAsync.when(
        loading: () => Semantics(
          liveRegion: true,
          label: 'Loading...',
          child: const Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: ShimmerList(itemCount: 10),
          ),
        ),
        error: (e, _) => ErrorView(
          message: e.toString(),
          onRetry: () => ref.invalidate(paymentHistoryProvider('me')),
        ),
        data: (state) => _buildBody(context, state),
      ),
    );
  }

  Widget _buildBody(BuildContext context, PaymentHistoryState state) {
    if (state is PaymentHistoryLoading) {
      return Semantics(
        liveRegion: true,
        label: 'Loading...',
        child: const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 10),
        ),
      );
    }

    if (state is PaymentHistoryEmpty) {
      return Column(
        children: [
          _FilterBar(state.filter),
          Expanded(
            child: Center(
              child: Semantics(
                liveRegion: true,
                child: const Text('No transactions found'),
              ),
            ),
          ),
        ],
      );
    }

    if (state is PaymentHistoryError) {
      if (state.staleItems != null && state.staleItems!.isNotEmpty) {
        return Column(
          children: [
            _StaleBanner(staleAt: state.staleAt),
            Expanded(
              child: _ItemList(
                items: state.staleItems!,
                scrollController: _scrollController,
              ),
            ),
          ],
        );
      }
      return ErrorView(
        message: state.isOffline
            ? 'No internet connection. Pull to refresh.'
            : state.message,
        onRetry: () => ref.invalidate(paymentHistoryProvider('me')),
      );
    }

    if (state is PaymentHistoryRefreshing) {
      return Column(
        children: [
          _FilterBar(state.filter),
          const LinearProgressIndicator(),
          Expanded(
            child: _ItemList(
              items: state.currentItems,
              scrollController: _scrollController,
            ),
          ),
        ],
      );
    }

    if (state is PaymentHistoryLoaded) {
      return RefreshIndicator(
        onRefresh: () =>
            ref.read(paymentHistoryProvider('me').notifier).refresh(),
        child: Column(
          children: [
            _FilterBar(state.filter),
            if (state.isStale)
              _StaleBanner(staleAt: state.cachedAt),
            Expanded(
              child: _ItemList(
                items: state.items,
                scrollController: _scrollController,
              ),
            ),
          ],
        ),
      );
    }

    return const SizedBox.shrink();
  }
}

class _FilterBar extends ConsumerWidget {
  final PaymentHistoryFilter filter;
  const _FilterBar(this.filter);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return HistoryFilterBar(
      currentFilter: filter,
      onFilterChanged: (f) =>
          ref.read(paymentHistoryProvider('me').notifier).applyFilter(f),
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
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.xs,
        ),
        child: Text(label, style: AppTypography.caption),
      ),
    );
  }
}

class _ItemList extends StatelessWidget {
  final List items;
  final ScrollController scrollController;

  const _ItemList({required this.items, required this.scrollController});

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      controller: scrollController,
      itemCount: items.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, i) {
        final tx = items[i];
        return SizedBox(
          height: 72,
          child: InkWell(
            onTap: () => context.go(PaymentRoutes.detailRoute(tx.id)),
            child: TransactionListTile(tx: tx),
          ),
        );
      },
    );
  }
}

import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_agent/modules/transactions/data/models/transaction_dto.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

// ─── State ────────────────────────────────────────────────────────────────────

sealed class PaymentHistoryState {
  const PaymentHistoryState();
}

final class PaymentHistoryLoading extends PaymentHistoryState {
  const PaymentHistoryLoading();
}

final class PaymentHistoryLoaded extends PaymentHistoryState {
  final List<PaymentHistoryItem> items;
  final PaymentHistoryFilter filter;
  final PaginatedResult<PaymentHistoryItem> pagination;
  final bool isStale;
  final DateTime? cachedAt;

  const PaymentHistoryLoaded({
    required this.items,
    required this.filter,
    required this.pagination,
    this.isStale = false,
    this.cachedAt,
  });
}

final class PaymentHistoryEmpty extends PaymentHistoryState {
  final PaymentHistoryFilter filter;
  const PaymentHistoryEmpty({required this.filter});
}

final class PaymentHistoryRefreshing extends PaymentHistoryState {
  final List<PaymentHistoryItem> currentItems;
  final PaymentHistoryFilter filter;
  const PaymentHistoryRefreshing({
    required this.currentItems,
    required this.filter,
  });
}

final class PaymentHistoryError extends PaymentHistoryState {
  final String message;
  final bool isOffline;
  final List<PaymentHistoryItem>? staleItems;
  final DateTime? staleAt;

  const PaymentHistoryError({
    required this.message,
    required this.isOffline,
    this.staleItems,
    this.staleAt,
  });
}

// ─── Notifier ─────────────────────────────────────────────────────────────────

class PaymentHistoryNotifier extends AsyncNotifier<PaymentHistoryState> {
  final String userId;
  PaymentHistoryNotifier(this.userId);

  late TransactionRepository _repo;
  late PaymentCacheStore _cache;
  PaymentHistoryFilter _filter = const PaymentHistoryFilter();

  @override
  Future<PaymentHistoryState> build() async {
    _repo = ref.watch(transactionRepositoryProvider);
    _cache = ref.watch(paymentCacheStoreProvider);
    return _load(userId, _filter);
  }

  Future<PaymentHistoryState> _load(
      String userId, PaymentHistoryFilter filter) async {
    try {
      final page = await _repo.fetchHistory(filter);
      if (page.data.isEmpty && filter.page == 1) {
        return PaymentHistoryEmpty(filter: filter);
      }
      return PaymentHistoryLoaded(
        items: page.data,
        filter: filter,
        pagination: page,
        isStale: false,
      );
    } on DioException catch (e) {
      final cached = await _cache.readHistoryPage(userId);
      List<PaymentHistoryItem>? staleItems;
      DateTime? staleAt;
      if (cached != null) {
        try {
          final decoded = jsonDecode(cached.rawJson) as Map<String, dynamic>;
          final paged =
              PaginatedResult.fromJson(decoded, AgentTransactionDto.fromJson);
          staleItems = paged.data;
        } catch (_) {}
        staleAt = cached.cachedAt;
      }
      return PaymentHistoryError(
        message: e.message ?? 'Network error',
        isOffline: true,
        staleItems: staleItems,
        staleAt: staleAt,
      );
    }
  }

  Future<void> applyFilter(PaymentHistoryFilter filter) async {
    _filter = filter.copyWith(page: 1);
    state = const AsyncValue.loading();
    state = AsyncValue.data(await _load(userId, _filter));
  }

  Future<void> loadNextPage() async {
    final current = state.value;
    if (current is! PaymentHistoryLoaded) return;
    if (!current.pagination.hasNextPage) return;

    final nextFilter = _filter.nextPage();
    _filter = nextFilter;
    try {
      final nextPage = await _repo.fetchHistory(nextFilter);
      final allItems = [...current.items, ...nextPage.data];
      state = AsyncValue.data(PaymentHistoryLoaded(
        items: allItems,
        filter: _filter,
        pagination: nextPage,
      ));
    } catch (_) {
      _filter = current.filter;
    }
  }

  Future<void> refresh() async {
    final current = state.value;
    if (current is PaymentHistoryLoaded) {
      state = AsyncValue.data(PaymentHistoryRefreshing(
        currentItems: current.items,
        filter: _filter,
      ));
    } else {
      state = const AsyncValue.loading();
    }
    _filter = _filter.copyWith(page: 1);
    state = AsyncValue.data(await _load(userId, _filter));
  }
}

final paymentHistoryProvider = AsyncNotifierProvider.family<
    PaymentHistoryNotifier, PaymentHistoryState, String>(
  PaymentHistoryNotifier.new,
);

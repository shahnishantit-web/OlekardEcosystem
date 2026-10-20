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
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

class TransactionListScreen extends ConsumerWidget {
  const TransactionListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final transactionsAsync = ref.watch(transactionsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Transactions')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: transactionsAsync.when(
              loading: () => const ShimmerList(itemCount: 8),
              error: (e, _) => ErrorView(
                message: 'Failed to load transactions.',
                onRetry: () => ref.invalidate(transactionsProvider),
              ),
              data: (page) => page.data.isEmpty
                  ? const Center(child: Text('No transactions yet.'))
                  : ListView.builder(
                      itemCount: page.data.length,
                      itemBuilder: (ctx, i) =>
                          _TransactionTile(tx: page.data[i]),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  final AgentTransaction tx;
  const _TransactionTile({required this.tx});

  @override
  Widget build(BuildContext context) {
    final isCredit = tx.type == AgentTransactionType.floatTopUp ||
        tx.type == AgentTransactionType.commission ||
        tx.type == AgentTransactionType.cashOut;

    return Semantics(
      label:
          '${_typeLabel(tx.type)}, ${tx.counterpartyName}, ${tx.amountMinorUnits ~/ 100} ${tx.currencyCode}',
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor:
              isCredit ? AppColors.successLight : AppColors.errorLight,
          child: Icon(
            isCredit ? Icons.arrow_downward : Icons.arrow_upward,
            color: isCredit ? AppColors.success : AppColors.error,
          ),
        ),
        title: Text(tx.counterpartyName),
        subtitle: Text(
          '${_typeLabel(tx.type)} · ${DateFormat('d MMM, HH:mm').format(tx.createdAt)}',
          style: AppTypography.bodySmall
              .copyWith(color: AppColors.onSurfaceVariant),
        ),
        trailing: AmountDisplay(
          minorUnits: tx.amountMinorUnits,
          currencyCode: tx.currencyCode,
          size: AmountSize.small,
          color: isCredit ? AppColors.success : null,
        ),
        onTap: () => context.push('/transactions/${tx.id}'),
      ),
    );
  }

  String _typeLabel(AgentTransactionType type) {
    switch (type) {
      case AgentTransactionType.cashIn:
        return 'Cash In';
      case AgentTransactionType.cashOut:
        return 'Cash Out';
      case AgentTransactionType.floatTopUp:
        return 'Float Top-Up';
      case AgentTransactionType.commission:
        return 'Commission';
      case AgentTransactionType.reversal:
        return 'Reversal';
      default:
        return 'Transaction';
    }
  }
}

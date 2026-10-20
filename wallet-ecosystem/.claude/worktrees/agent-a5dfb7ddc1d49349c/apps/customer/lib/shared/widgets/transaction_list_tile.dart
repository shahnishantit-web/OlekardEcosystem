import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../modules/transactions/domain/entities/transaction.dart';
import '../../core/theme/design_tokens.dart';
import 'amount_display.dart';

class TransactionListTile extends StatelessWidget {
  final Transaction tx;

  const TransactionListTile({super.key, required this.tx});

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.xs,
      ),
      leading: CircleAvatar(
        backgroundColor: AppColors.background,
        child: Icon(_typeIcon(tx.type), color: AppColors.primary, size: 20),
      ),
      title: Text(
        tx.counterpartyName ?? _typeLabel(tx.type),
        style: AppTypography.label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Text(
        DateFormat.yMMMd().format(tx.createdAt),
        style: AppTypography.caption.copyWith(color: Colors.grey),
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          AmountDisplay(
            minorUnits: tx.amountMinorUnits,
            currencyCode: tx.currencyCode,
            style: AppTypography.label.copyWith(
              color: tx.type == TransactionType.transfer
                  ? AppColors.error
                  : AppColors.success,
            ),
          ),
          _StatusChip(status: tx.status),
        ],
      ),
    );
  }

  IconData _typeIcon(TransactionType type) {
    switch (type) {
      case TransactionType.fundingBankTransfer:
      case TransactionType.fundingAgentCashIn:
        return Icons.add_circle_outline;
      case TransactionType.transfer:
        return Icons.send_outlined;
      case TransactionType.withdrawal:
        return Icons.money_off;
      case TransactionType.servicePayment:
        return Icons.receipt_outlined;
      case TransactionType.fxConversion:
        return Icons.currency_exchange;
    }
  }

  String _typeLabel(TransactionType type) {
    switch (type) {
      case TransactionType.fundingBankTransfer:
        return 'Bank Transfer';
      case TransactionType.fundingAgentCashIn:
        return 'Agent Cash-In';
      case TransactionType.transfer:
        return 'Transfer';
      case TransactionType.withdrawal:
        return 'Withdrawal';
      case TransactionType.servicePayment:
        return 'Service Payment';
      case TransactionType.fxConversion:
        return 'FX Conversion';
    }
  }
}

class _StatusChip extends StatelessWidget {
  final TransactionStatus status;

  const _StatusChip({required this.status});

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (status) {
      TransactionStatus.pending => ('Pending', Colors.orange),
      TransactionStatus.processing => ('Processing', Colors.blue),
      TransactionStatus.completed => ('Completed', AppColors.success),
      TransactionStatus.failed => ('Failed', AppColors.error),
      TransactionStatus.reversed => ('Reversed', Colors.grey),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(label, style: TextStyle(fontSize: 10, color: color)),
    );
  }
}

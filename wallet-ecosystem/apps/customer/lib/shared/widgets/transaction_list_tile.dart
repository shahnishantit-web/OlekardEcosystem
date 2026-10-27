import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../modules/transactions/domain/entities/transaction.dart';
import '../../core/theme/design_tokens.dart';
import 'amount_display.dart';

class TransactionListTile extends StatelessWidget {
  final PaymentHistoryItem tx;

  const TransactionListTile({super.key, required this.tx});

  @override
  Widget build(BuildContext context) {
    final isCredit = tx.direction == PaymentDirection.credit;
    return Semantics(
      label: '${tx.displayTitle}, '
          '${tx.amountMinorUnits} ${tx.currencyCode}, '
          '${tx.status.name}',
      child: ListTile(
        minVerticalPadding: 0,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.xs,
        ),
        leading: CircleAvatar(
          backgroundColor: AppColors.background,
          child:
              Icon(_typeIcon(tx.purposeType), color: AppColors.primary, size: 20),
        ),
        title: Text(
          tx.displayTitle,
          style: AppTypography.label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        subtitle: Text(
          DateFormat.yMMMd().format(tx.createdAt),
          style: AppTypography.caption.copyWith(color: Colors.grey),
        ),
        trailing: FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerRight,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              AmountDisplay(
                amountMinorUnits: tx.amountMinorUnits,
                currencyCode: tx.currencyCode,
                style: AppTypography.label.copyWith(
                  color: isCredit ? AppColors.success : AppColors.error,
                ),
              ),
              _StatusChip(status: tx.status),
            ],
          ),
        ),
      ),
    );
  }

  static IconData _typeIcon(PaymentPurposeType type) => switch (type) {
        PaymentPurposeType.fundingBankTransfer ||
        PaymentPurposeType.fundingAgentCashIn =>
          Icons.add_circle_outline,
        PaymentPurposeType.walletTransfer => Icons.send_outlined,
        PaymentPurposeType.withdrawal => Icons.money_off,
        PaymentPurposeType.servicePayment ||
        PaymentPurposeType.billPayment =>
          Icons.receipt_outlined,
        PaymentPurposeType.merchantPayment => Icons.storefront_outlined,
        PaymentPurposeType.fxConversion => Icons.currency_exchange,
        PaymentPurposeType.refund => Icons.replay_outlined,
        PaymentPurposeType.reversal => Icons.undo_outlined,
        PaymentPurposeType.unknown => Icons.help_outline,
      };
}

class _StatusChip extends StatelessWidget {
  final PaymentStatus status;

  const _StatusChip({required this.status});

  @override
  Widget build(BuildContext context) {
    final (label, color) = switch (status) {
      PaymentStatus.pending => ('Pending', Colors.orange),
      PaymentStatus.processing => ('Processing', Colors.blue),
      PaymentStatus.completed => ('Completed', AppColors.success),
      PaymentStatus.failed => ('Failed', AppColors.error),
      PaymentStatus.reversed => ('Reversed', Colors.grey),
      PaymentStatus.cancelled => ('Cancelled', Colors.grey),
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

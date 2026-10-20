import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../transaction_providers.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/amount_display.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class TransactionDetailScreen extends ConsumerStatefulWidget {
  final String id;
  const TransactionDetailScreen({super.key, required this.id});

  @override
  ConsumerState<TransactionDetailScreen> createState() =>
      _TransactionDetailScreenState();
}

class _TransactionDetailScreenState
    extends ConsumerState<TransactionDetailScreen> {
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final txAsync = ref.watch(transactionDetailProvider(widget.id));

    return Scaffold(
      appBar: AppBar(title: const Text('Transaction Detail')),
      body: txAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 6),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (tx) => ListView(
          padding: const EdgeInsets.all(AppSpacing.lg),
          children: [
            _Row('Reference', tx.referenceNumber),
            _Row('Type', tx.type.name),
            _Row('Status', tx.status.name),
            if (tx.counterpartyName != null)
              _Row('Counterparty', tx.counterpartyName!),
            _RowAmount('Amount', tx.amountMinorUnits, tx.currencyCode),
            _RowAmount('Fee', tx.feeMinorUnits, tx.currencyCode),
            _RowAmount('Net', (tx.amountMinorUnits + tx.feeMinorUnits),
                tx.currencyCode),
            _Row('Date', DateFormat.yMMMMd().add_jm().format(tx.createdAt)),
            if (tx.description != null) _Row('Description', tx.description!),
            const SizedBox(height: AppSpacing.lg),
            OutlinedButton.icon(
              icon: const Icon(Icons.report_problem_outlined),
              label: const Text('Dispute this transaction'),
              onPressed: () => context.push('/disputes/new/${tx.id}'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label,
                style: AppTypography.caption.copyWith(color: Colors.grey)),
            Text(value, style: AppTypography.label),
          ],
        ),
      );
}

class _RowAmount extends StatelessWidget {
  final String label;
  final int minorUnits;
  final String currencyCode;
  const _RowAmount(this.label, this.minorUnits, this.currencyCode);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label,
                style: AppTypography.caption.copyWith(color: Colors.grey)),
            AmountDisplay(
                amountMinorUnits: minorUnits,
                currencyCode: currencyCode,
                style: AppTypography.label),
          ],
        ),
      );
}

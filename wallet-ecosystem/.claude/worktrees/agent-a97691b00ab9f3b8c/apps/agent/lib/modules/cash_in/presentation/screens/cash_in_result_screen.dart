import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/receipt/receipt_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/success_view.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';
import 'package:wallet_agent/modules/float/presentation/float_providers.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';

class CashInResultScreen extends ConsumerWidget {
  final CashInResult result;
  const CashInResultScreen({super.key, required this.result});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cash In Complete')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            Expanded(
              child: SuccessView(
                referenceNumber: result.referenceNumber,
                additionalContent: _SummaryCard(result: result),
              ),
            ),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.share),
                    label: const Text('Share Receipt'),
                    onPressed: () => _shareReceipt(context),
                  ),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: FilledButton(
                    onPressed: () {
                      ref.invalidate(dashboardProvider);
                      ref.invalidate(floatBalanceProvider);
                      context.go('/dashboard');
                    },
                    child: const Text('Done'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _shareReceipt(BuildContext context) async {
    final data = ReceiptData(
      type: 'Cash In',
      referenceNumber: result.referenceNumber,
      amountMinorUnits: result.amountMinorUnits,
      feeMinorUnits: result.feeMinorUnits,
      netMinorUnits: result.netMinorUnits,
      currencyCode: result.currencyCode,
      customerName: result.customerName,
      timestamp: result.timestamp,
    );
    await ReceiptService.shareReceipt(data);
  }
}

class _SummaryCard extends StatelessWidget {
  final CashInResult result;
  const _SummaryCard({required this.result});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          children: [
            _Row(label: 'Customer', value: result.customerName),
            const Divider(),
            _AmountRow(
              label: 'Amount',
              minorUnits: result.amountMinorUnits,
              currency: result.currencyCode,
            ),
            _AmountRow(
              label: 'Fee',
              minorUnits: result.feeMinorUnits,
              currency: result.currencyCode,
            ),
            const Divider(),
            _AmountRow(
              label: 'Float Debited',
              minorUnits: result.netMinorUnits,
              currency: result.currencyCode,
              bold: true,
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
  const _Row({required this.label, required this.value});
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant)),
          Text(value, style: AppTypography.bodyMedium),
        ],
      ),
    );
  }
}

class _AmountRow extends StatelessWidget {
  final String label;
  final int minorUnits;
  final String currency;
  final bool bold;
  const _AmountRow({
    required this.label,
    required this.minorUnits,
    required this.currency,
    this.bold = false,
  });
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: bold
                ? AppTypography.titleSmall
                : AppTypography.bodyMedium
                    .copyWith(color: AppColors.onSurfaceVariant),
          ),
          AmountDisplay(
            minorUnits: minorUnits,
            currencyCode: currency,
            size: bold ? AmountSize.medium : AmountSize.small,
          ),
        ],
      ),
    );
  }
}

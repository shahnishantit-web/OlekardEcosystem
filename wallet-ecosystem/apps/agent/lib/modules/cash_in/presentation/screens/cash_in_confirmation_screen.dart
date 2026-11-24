import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/security/screenshot_guard.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';
import 'package:wallet_agent/modules/cash_in/presentation/cash_in_providers.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';
import 'package:wallet_agent/shared/widgets/transaction_step_flow.dart';

class CashInConfirmationScreen extends ConsumerStatefulWidget {
  final String customerId;
  final String walletId;
  final String customerName;
  final int amountMinorUnits;
  final String currencyCode;

  const CashInConfirmationScreen({
    super.key,
    required this.customerId,
    required this.walletId,
    required this.customerName,
    required this.amountMinorUnits,
    required this.currencyCode,
  });

  @override
  ConsumerState<CashInConfirmationScreen> createState() =>
      _CashInConfirmationScreenState();
}

class _CashInConfirmationScreenState
    extends ConsumerState<CashInConfirmationScreen> {
  bool _isLoading = false;
  String? _pinError;

  @override
  void initState() {
    super.initState();
    ScreenshotGuard.enable();
  }

  @override
  void dispose() {
    ScreenshotGuard.disable();
    super.dispose();
  }

  Future<void> _submitCashIn() async {
    setState(() {
      _isLoading = true;
      _pinError = null;
    });
    try {
      final result = await ref.read(cashInRepositoryProvider).submitCashIn(
            CashInRequestDto(
              customerId: widget.customerId,
              customerName: widget.customerName,
              walletId: widget.walletId,
              amountMinorUnits: widget.amountMinorUnits,
              currencyCode: widget.currencyCode,
            ),
          );
      ref.read(cashInResultProvider.notifier).setResult(result);
      ref.invalidate(dashboardProvider);
      if (mounted) context.go('/cash-in/result', extra: result);
    } on DioException catch (e) {
      final payload = e.response?.data as Map<String, dynamic>?;
      final error = payload?['error'] as Map<String, dynamic>?;
      final code = (error?['code'] ?? payload?['code']) as String?;
      if ((code == 'step_up_required' || code == 'STEP_UP_REQUIRED') &&
          mounted) {
        context.push('/step-up-pin');
      } else {
        setState(() {
          _pinError = (error?['message'] ?? payload?['message']) as String? ??
              'Transaction failed. Please try again.';
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Confirm Cash In')),
      body: TransactionStepFlow(
        steps: const [
          'Find Customer',
          'Enter Amount',
          'Review Amount',
          'Confirm',
          'Result'
        ],
        currentStep: 3,
        child: Column(
          children: [
            const OfflineBanner(),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(AppSpacing.md),
                        child: Column(
                          children: [
                            _InfoRow(
                                label: 'Customer', value: widget.customerName),
                            const Divider(),
                            _AmountRow(
                              label: 'Amount',
                              minorUnits: widget.amountMinorUnits,
                              currency: widget.currencyCode,
                            ),
                            const Divider(),
                            _AmountRow(
                              label: 'Float Debit',
                              minorUnits: widget.amountMinorUnits,
                              currency: widget.currencyCode,
                              bold: true,
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    const Text('Review and confirm',
                        style: AppTypography.titleMedium),
                    const SizedBox(height: AppSpacing.md),
                    if (_pinError != null) ...[
                      Text(_pinError!,
                          style: const TextStyle(color: AppColors.error)),
                      const SizedBox(height: AppSpacing.sm),
                    ],
                    if (_isLoading)
                      const Center(child: CircularProgressIndicator())
                    else
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton.icon(
                          onPressed: _submitCashIn,
                          icon: const Icon(Icons.check),
                          label: const Text('Confirm Cash In'),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final String label;
  final String value;
  const _InfoRow({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant)),
          Text(value, style: AppTypography.titleMedium),
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
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: bold
                ? AppTypography.titleMedium
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

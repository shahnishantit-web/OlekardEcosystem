import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/security/pin_service.dart';
import 'package:wallet_agent/core/security/screenshot_guard.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/pin_pad_widget.dart';
import 'package:wallet_agent/modules/cash_out/data/models/cash_out_request_dto.dart';
import 'package:wallet_agent/modules/cash_out/presentation/cash_out_providers.dart';
import 'package:wallet_agent/modules/dashboard/presentation/dashboard_providers.dart';

class CashOutConfirmationScreen extends ConsumerStatefulWidget {
  final String walletId;
  final String customerName;
  final int amountMinorUnits;
  final String currencyCode;

  const CashOutConfirmationScreen({
    super.key,
    required this.walletId,
    required this.customerName,
    required this.amountMinorUnits,
    required this.currencyCode,
  });

  @override
  ConsumerState<CashOutConfirmationScreen> createState() =>
      _CashOutConfirmationScreenState();
}

class _CashOutConfirmationScreenState
    extends ConsumerState<CashOutConfirmationScreen> {
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

  Future<void> _onPinCompleted(String pin) async {
    // Verify online before PIN submission (T076)
    final isOnline = ref.read(connectivityProvider);
    if (!isOnline) {
      setState(() {
        _pinError = 'You are offline. Cash-out requires an internet connection.';
      });
      return;
    }

    setState(() {
      _isLoading = true;
      _pinError = null;
    });
    try {
      final pinHash = PinService.hash(pin);
      final result =
          await ref.read(cashOutRepositoryProvider).submitCashOut(
                CashOutRequestDto(
                  walletId: widget.walletId,
                  amountMinorUnits: widget.amountMinorUnits,
                  currencyCode: widget.currencyCode,
                  pinHash: pinHash,
                ),
              );
      ref.read(cashOutResultProvider.notifier).setResult(result);
      ref.invalidate(dashboardProvider);
      if (mounted) context.go('/cash-out/result', extra: result);
    } on DioException catch (e) {
      final code =
          (e.response?.data as Map<String, dynamic>?)?['code'] as String?;
      if (code == 'step_up_required' && mounted) {
        context.push('/step-up-pin');
      } else {
        setState(() {
          _pinError =
              (e.response?.data as Map<String, dynamic>?)?['message']
                      as String? ??
                  'Transaction failed. Please try again.';
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const estimatedFee = 250;

    return Scaffold(
      appBar: AppBar(title: const Text('Confirm Cash Out')),
      body: Column(
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
                              label: 'Customer',
                              value: widget.customerName),
                          const Divider(),
                          _AmountRow(
                            label: 'Amount',
                            minorUnits: widget.amountMinorUnits,
                            currency: widget.currencyCode,
                          ),
                          _AmountRow(
                            label: 'Estimated Fee',
                            minorUnits: estimatedFee,
                            currency: widget.currencyCode,
                          ),
                          const Divider(),
                          _AmountRow(
                            label: 'Float Credit',
                            minorUnits:
                                widget.amountMinorUnits - estimatedFee,
                            currency: widget.currencyCode,
                            bold: true,
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xl),
                  Semantics(
                    label: 'Enter your PIN to confirm the cash-out',
                    child: const Text(
                      'Enter your PIN to confirm',
                      style: AppTypography.titleMedium,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  if (_isLoading)
                    const Center(child: CircularProgressIndicator())
                  else
                    PinPadWidget(
                      onCompleted: _onPinCompleted,
                      errorText: _pinError,
                    ),
                ],
              ),
            ),
          ),
        ],
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

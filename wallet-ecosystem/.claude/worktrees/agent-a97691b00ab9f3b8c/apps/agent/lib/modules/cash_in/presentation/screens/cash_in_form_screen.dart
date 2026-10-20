import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';

class CashInFormScreen extends ConsumerStatefulWidget {
  final String walletId;
  final String customerName;
  final String maskedWalletNumber;

  const CashInFormScreen({
    super.key,
    required this.walletId,
    required this.customerName,
    required this.maskedWalletNumber,
  });

  @override
  ConsumerState<CashInFormScreen> createState() => _CashInFormScreenState();
}

class _CashInFormScreenState extends ConsumerState<CashInFormScreen> {
  final _amountController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  int _previewMinorUnits = 0;

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  void _onAmountChanged(String value) {
    final parsed = double.tryParse(value.replaceAll(',', '')) ?? 0;
    setState(() => _previewMinorUnits = (parsed * 100).round());
  }

  void _proceed() {
    if (!_formKey.currentState!.validate()) return;
    final isOnline = ref.read(connectivityProvider);
    if (!isOnline) {
      showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('You are offline'),
          content: const Text(
              'You must be connected to the internet to perform a cash-in.'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('OK'),
            ),
          ],
        ),
      );
      return;
    }
    context.push('/cash-in/confirm', extra: {
      'wallet_id': widget.walletId,
      'customer_name': widget.customerName,
      'amount_minor_units': _previewMinorUnits,
      'currency_code': 'BWP',
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cash In — Amount')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Customer', style: AppTypography.labelLarge),
                    Text(widget.customerName, style: AppTypography.titleLarge),
                    Text(
                      widget.maskedWalletNumber,
                      style: AppTypography.bodyMedium.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    TextFormField(
                      controller: _amountController,
                      keyboardType:
                          const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(
                        labelText: 'Amount (BWP)',
                        prefixIcon: Icon(Icons.attach_money),
                      ),
                      onChanged: _onAmountChanged,
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) {
                          return 'Amount is required';
                        }
                        final parsed =
                            double.tryParse(v.replaceAll(',', ''));
                        if (parsed == null || parsed <= 0) {
                          return 'Enter a valid amount';
                        }
                        return null;
                      },
                    ),
                    if (_previewMinorUnits > 0) ...[
                      const SizedBox(height: AppSpacing.md),
                      Row(
                        children: [
                          const Text('Preview: ',
                              style: AppTypography.bodyMedium),
                          AmountDisplay(
                            minorUnits: _previewMinorUnits,
                            currencyCode: 'BWP',
                            size: AmountSize.medium,
                          ),
                        ],
                      ),
                    ],
                    const SizedBox(height: AppSpacing.xl),
                    PrimaryButton(
                      label: 'Review Transaction',
                      onPressed: _proceed,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

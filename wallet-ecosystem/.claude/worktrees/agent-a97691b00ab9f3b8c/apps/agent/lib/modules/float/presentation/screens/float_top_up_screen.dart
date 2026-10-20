import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/amount_display.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/shared/widgets/success_view.dart';
import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';
import 'package:wallet_agent/modules/float/presentation/float_providers.dart';

class FloatTopUpScreen extends ConsumerStatefulWidget {
  const FloatTopUpScreen({super.key});

  @override
  ConsumerState<FloatTopUpScreen> createState() => _FloatTopUpScreenState();
}

class _FloatTopUpScreenState extends ConsumerState<FloatTopUpScreen> {
  final _amountController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _isLoading = false;
  String? _errorMessage;
  FloatTopUpResult? _result;

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final amountStr = _amountController.text.replaceAll(',', '');
    final amount = double.tryParse(amountStr) ?? 0;
    final amountMinorUnits = (amount * 100).round();

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final result = await ref.read(floatRepositoryProvider).requestTopUp(
            FloatTopUpRequest(
              amountMinorUnits: amountMinorUnits,
              currencyCode: 'BWP',
            ),
          );
      ref.invalidate(floatBalanceProvider);
      setState(() => _result = result);
    } on DioException catch (e) {
      setState(() {
        _errorMessage =
            (e.response?.data as Map<String, dynamic>?)?['message']
                    as String? ??
                'Request failed. Please try again.';
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_result != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Top-Up Requested')),
        body: Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            children: [
              Expanded(
                child: SuccessView(
                  referenceNumber: _result!.referenceNumber,
                  additionalContent: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(AppSpacing.md),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text('Requested Amount',
                              style: AppTypography.bodyMedium),
                          AmountDisplay(
                            minorUnits: _result!.amountMinorUnits,
                            currencyCode: _result!.currencyCode,
                            size: AmountSize.medium,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              FilledButton(
                onPressed: () => context.go('/float'),
                child: const Text('Back to Float'),
              ),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Request Float Top-Up')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Form(
                key: _formKey,
                child: Column(
                  children: [
                    TextFormField(
                      controller: _amountController,
                      keyboardType: const TextInputType.numberWithOptions(
                          decimal: true),
                      decoration: const InputDecoration(
                        labelText: 'Amount to Request (BWP)',
                        prefixIcon: Icon(Icons.attach_money),
                      ),
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) return 'Required';
                        final parsed =
                            double.tryParse(v.replaceAll(',', ''));
                        if (parsed == null || parsed <= 0) {
                          return 'Enter a valid amount';
                        }
                        return null;
                      },
                    ),
                    if (_errorMessage != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      Text(_errorMessage!,
                          style: const TextStyle(color: AppColors.error)),
                    ],
                    const SizedBox(height: AppSpacing.xl),
                    PrimaryButton(
                      label: 'Submit Request',
                      isLoading: _isLoading,
                      onPressed: _submit,
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

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/cash_out/presentation/cash_out_providers.dart';

class CashOutCustomerSearchScreen extends ConsumerStatefulWidget {
  const CashOutCustomerSearchScreen({super.key});

  @override
  ConsumerState<CashOutCustomerSearchScreen> createState() =>
      _CashOutCustomerSearchScreenState();
}

class _CashOutCustomerSearchScreenState
    extends ConsumerState<CashOutCustomerSearchScreen> {
  final _searchController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;
  dynamic _result;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final q = _searchController.text.trim();
    if (q.isEmpty) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
      _result = null;
    });
    try {
      final lookup =
          await ref.read(cashOutRepositoryProvider).searchCustomer(q);
      setState(() => _result = lookup);
    } on DioException catch (e) {
      final statusCode = e.response?.statusCode;
      setState(() {
        _errorMessage = statusCode == 404
            ? 'No active customer found.'
            : 'Search failed. Please try again.';
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Cash Out — Find Customer')),
      body: Column(
        children: [
          const OfflineBanner(),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                TextField(
                  controller: _searchController,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: 'Phone number or wallet number',
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: IconButton(
                      icon: const Icon(Icons.clear),
                      onPressed: () {
                        _searchController.clear();
                        setState(() {
                          _result = null;
                          _errorMessage = null;
                        });
                      },
                    ),
                  ),
                  onSubmitted: (_) => _search(),
                ),
                const SizedBox(height: AppSpacing.md),
                PrimaryButton(
                  label: 'Search',
                  isLoading: _isLoading,
                  onPressed: _search,
                  icon: Icons.search,
                ),
                if (_errorMessage != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  Text(_errorMessage!,
                      style: const TextStyle(color: AppColors.error)),
                ],
                if (_result != null) ...[
                  const SizedBox(height: AppSpacing.lg),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(AppSpacing.md),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(_result.customerName,
                              style: AppTypography.titleLarge),
                          Text(_result.maskedWalletNumber,
                              style: AppTypography.bodyMedium),
                          const SizedBox(height: AppSpacing.sm),
                          Chip(
                            label: Text(_result.accountStatus),
                            backgroundColor: _result.isActive
                                ? AppColors.successLight
                                : AppColors.errorLight,
                          ),
                          const SizedBox(height: AppSpacing.md),
                          PrimaryButton(
                            label: 'Confirm Customer',
                            onPressed: _result.isActive
                                ? () => context
                                    .push('/cash-out/form', extra: {
                                  'wallet_id': _result.walletId,
                                  'customer_name': _result.customerName,
                                  'masked_wallet_number':
                                      _result.maskedWalletNumber,
                                })
                                : null,
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

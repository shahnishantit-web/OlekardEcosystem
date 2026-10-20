import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/customer_onboarding_providers.dart';

class CustomerLookupScreen extends ConsumerStatefulWidget {
  const CustomerLookupScreen({super.key});

  @override
  ConsumerState<CustomerLookupScreen> createState() =>
      _CustomerLookupScreenState();
}

class _CustomerLookupScreenState extends ConsumerState<CustomerLookupScreen> {
  final _searchController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;
  CustomerStatus? _result;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final query = _searchController.text.trim();
    if (query.isEmpty) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
      _result = null;
    });
    try {
      // Search by phone returns a customer ID; use it to get status
      final status = await ref
          .read(customerOnboardingRepositoryProvider)
          .getCustomerStatus(query);
      setState(() => _result = status);
    } on DioException catch (e) {
      setState(() {
        _errorMessage = e.response?.statusCode == 404
            ? 'No customer found with this phone number.'
            : 'Search failed. Please try again.';
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Customer Lookup')),
      body: Column(
        children: [
          const OfflineBanner(),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  label: 'Search customer by phone number',
                  child: TextField(
                    controller: _searchController,
                    keyboardType: TextInputType.phone,
                    decoration: InputDecoration(
                      labelText: 'Phone number',
                      hintText: 'e.g. +267 71 234 567',
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
                ),
                const SizedBox(height: AppSpacing.md),
                PrimaryButton(
                  label: 'Look Up Customer',
                  isLoading: _isLoading,
                  onPressed: _search,
                  icon: Icons.person_search,
                ),
                if (_errorMessage != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  Text(_errorMessage!,
                      style: const TextStyle(color: AppColors.error)),
                ],
                if (_result != null) ...[
                  const SizedBox(height: AppSpacing.lg),
                  _CustomerResultCard(
                    status: _result!,
                    onViewDetails: () =>
                        context.push('/customers/${_result!.customerId}/status'),
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

class _CustomerResultCard extends StatelessWidget {
  final CustomerStatus status;
  final VoidCallback onViewDetails;

  const _CustomerResultCard({
    required this.status,
    required this.onViewDetails,
  });

  /// Returns "FirstName L." masking the full last name to initial only.
  String _maskedName(String fullName) {
    final parts = fullName.trim().split(RegExp(r'\s+'));
    if (parts.length < 2) return parts.first;
    final lastName = parts.last;
    final firstName = parts.first;
    return '$firstName ${lastName[0]}.';
  }

  /// Masks middle digits: "+26771234567" → "+267 71 *** 567".
  String _maskedPhone(String phone) {
    final digits = phone.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 7) return '***';
    final prefix = phone.substring(0, phone.length - 7);
    final last3 = digits.substring(digits.length - 3);
    return '$prefix *** $last3';
  }

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_maskedName(status.customerName), style: AppTypography.titleLarge),
            const SizedBox(height: AppSpacing.xs),
            Text(
              _maskedPhone(status.phoneNumber),
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant),
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                _StatusChip(
                  label: 'Account: ${status.accountStatus}',
                  color: status.accountStatus == 'active'
                      ? AppColors.success
                      : AppColors.warning,
                ),
                const SizedBox(width: AppSpacing.sm),
                _StatusChip(
                  label: 'KYC: ${status.kycStatus}',
                  color: status.isKycVerified
                      ? AppColors.success
                      : status.isKycPending
                          ? AppColors.warning
                          : AppColors.error,
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.md),
            OutlinedButton.icon(
              icon: const Icon(Icons.info_outline),
              label: const Text('View Details'),
              onPressed: onViewDetails,
              style: OutlinedButton.styleFrom(
                minimumSize: const Size.fromHeight(AppSpacing.minTouchTarget),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  final String label;
  final Color color;

  const _StatusChip({required this.label, required this.color});

  @override
  Widget build(BuildContext context) {
    return Chip(
      label: Text(label,
          style: AppTypography.labelSmall.copyWith(color: color)),
      backgroundColor: color.withValues(alpha: 0.1),
      side: BorderSide(color: color, width: 1),
      padding: EdgeInsets.zero,
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/error_view.dart';
import 'package:wallet_agent/shared/widgets/loading_shimmer.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/customer_onboarding_providers.dart';

final _customerStatusProvider =
    FutureProvider.autoDispose.family<CustomerStatus, String>((ref, id) {
  return ref.read(customerOnboardingRepositoryProvider).getCustomerStatus(id);
});

class CustomerStatusScreen extends ConsumerWidget {
  final String customerId;

  const CustomerStatusScreen({super.key, required this.customerId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusAsync = ref.watch(_customerStatusProvider(customerId));

    return Scaffold(
      appBar: AppBar(title: const Text('Customer Status')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: statusAsync.when(
              loading: () => const ShimmerList(itemCount: 4),
              error: (e, _) => ErrorView(
                message: 'Failed to load customer status.',
                onRetry: () =>
                    ref.invalidate(_customerStatusProvider(customerId)),
              ),
              data: (status) => _StatusBody(
                status: status,
                onInitiateKyc: () => context
                    .push('/customers/${status.customerId}/kyc', extra: {
                  'customer_name': status.customerName,
                }),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusBody extends StatelessWidget {
  final CustomerStatus status;
  final VoidCallback onInitiateKyc;

  const _StatusBody({required this.status, required this.onInitiateKyc});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Semantics(
                  label: 'Customer: ${status.customerName}',
                  child: Text(status.customerName,
                      style: AppTypography.titleLarge),
                ),
                const SizedBox(height: AppSpacing.xs),
                Text(status.phoneNumber,
                    style: AppTypography.bodyMedium
                        .copyWith(color: AppColors.onSurfaceVariant)),
                const SizedBox(height: AppSpacing.md),
                _InfoRow(label: 'Account Status', value: status.accountStatus),
                const Divider(height: AppSpacing.lg),
                _InfoRow(label: 'KYC Status', value: status.kycStatus),
              ],
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        if (!status.isKycVerified) ...[
          Card(
            color: status.isKycPending
                ? AppColors.warningLight
                : AppColors.errorLight,
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    status.isKycPending
                        ? 'KYC Under Review'
                        : 'KYC Not Submitted',
                    style: AppTypography.titleSmall.copyWith(
                      color: status.isKycPending
                          ? AppColors.warning
                          : AppColors.error,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    status.isKycPending
                        ? 'Identity documents have been submitted and are being reviewed.'
                        : 'Identity documents have not been submitted. Initiate KYC to activate this account.',
                    style: AppTypography.bodySmall,
                  ),
                  if (!status.isKycPending) ...[
                    const SizedBox(height: AppSpacing.md),
                    PrimaryButton(
                      label: 'Initiate KYC',
                      onPressed: onInitiateKyc,
                      icon: Icons.camera_alt_outlined,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ],
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
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label,
              style: AppTypography.bodyMedium
                  .copyWith(color: AppColors.onSurfaceVariant)),
          Text(value.replaceAll('_', ' ').toUpperCase(),
              style: AppTypography.labelMedium),
        ],
      ),
    );
  }
}

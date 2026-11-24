import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/customer_onboarding_providers.dart';

class KycCaptureScreen extends ConsumerStatefulWidget {
  final String customerId;
  final String customerName;

  const KycCaptureScreen({
    super.key,
    required this.customerId,
    required this.customerName,
  });

  @override
  ConsumerState<KycCaptureScreen> createState() => _KycCaptureScreenState();
}

class _KycCaptureScreenState extends ConsumerState<KycCaptureScreen> {
  bool _isSubmitting = false;
  String? _errorMessage;

  Future<void> _submit() async {
    setState(() {
      _isSubmitting = true;
      _errorMessage = null;
    });
    try {
      await ref
          .read(customerOnboardingRepositoryProvider)
          .initiateKyc(widget.customerId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('KYC review has been initiated.')),
      );
      context.pop();
    } on DioException catch (e) {
      final message = (e.response?.data as Map<String, dynamic>?)?['message'];
      setState(() => _errorMessage = message as String? ??
          'Could not initiate KYC. Check the customer status and try again.');
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Initiate Customer KYC')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(widget.customerName, style: AppTypography.titleLarge),
                  const SizedBox(height: AppSpacing.md),
                  const Text(
                    'Start the KYC review for this linked customer. The current service starts the review workflow; document capture and upload are handled outside this Agent API.',
                    style: AppTypography.bodyMedium,
                  ),
                  const Spacer(),
                  if (_errorMessage != null) ...[
                    Text(_errorMessage!,
                        style: const TextStyle(color: AppColors.error)),
                    const SizedBox(height: AppSpacing.md),
                  ],
                  PrimaryButton(
                    label: 'Initiate KYC Review',
                    isLoading: _isSubmitting,
                    onPressed: _submit,
                    icon: Icons.verified_user_outlined,
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

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../payment/application/customer_money_service.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';

class StepUpPinScreen extends ConsumerStatefulWidget {
  const StepUpPinScreen({super.key});

  @override
  ConsumerState<StepUpPinScreen> createState() => _StepUpPinScreenState();
}

class _StepUpPinScreenState extends ConsumerState<StepUpPinScreen> {
  bool _isLoading = false;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Additional Verification Required')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            const SizedBox(height: AppSpacing.xl),
            const Icon(Icons.security, size: 48, color: AppColors.primary),
            const SizedBox(height: AppSpacing.md),
            const Text(
              'This action requires your PIN for extra security',
              style: AppTypography.body,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const CircularProgressIndicator()
            else
              PinPadWidget(
                onCompleted: _onPin,
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _onPin(String pin) async {
    setState(() => _isLoading = true);
    try {
      final stepUpToken =
          await ref.read(customerMoneyServiceProvider).verifyStepUp(pin);
      // Store for AuthInterceptor to pick up on retry
      AuthInterceptor.pendingStepUpToken = stepUpToken;
      if (mounted) context.pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Verification failed — please try again')),
        );
        setState(() => _isLoading = false);
      }
    }
  }
}

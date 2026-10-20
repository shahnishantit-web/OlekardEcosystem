import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/di/providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../modules/auth/presentation/auth_providers.dart';
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
                length: 6,
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
      final pinHash = await ref.read(pinServiceProvider).hashPin(pin);
      final dio = ref.read(apiClientProvider).dio;
      final res = await dio.post(
        '/v1/customer/auth/step-up',
        data: {'pin_hash': pinHash},
      );
      final stepUpToken = res.data['step_up_token'] as String;
      // Store for AuthInterceptor to pick up on retry
      AuthInterceptor.pendingStepUpToken = stepUpToken;
      if (mounted) context.pop();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Verification failed — please try again')),
        );
        setState(() => _isLoading = false);
      }
    }
  }
}

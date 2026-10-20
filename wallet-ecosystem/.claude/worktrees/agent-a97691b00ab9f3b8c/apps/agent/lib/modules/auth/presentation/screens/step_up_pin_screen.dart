import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/security/pin_service.dart';
import 'package:wallet_agent/core/security/screenshot_guard.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/pin_pad_widget.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

// In-memory step-up token — intentionally NOT persisted to secure storage.
String? _inMemoryStepUpToken;

String? getStepUpToken() => _inMemoryStepUpToken;
void clearStepUpToken() => _inMemoryStepUpToken = null;

class StepUpPinScreen extends ConsumerStatefulWidget {
  const StepUpPinScreen({super.key});

  @override
  ConsumerState<StepUpPinScreen> createState() => _StepUpPinScreenState();
}

class _StepUpPinScreenState extends ConsumerState<StepUpPinScreen> {
  bool _isLoading = false;
  String? _errorMessage;

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
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final pinHash = PinService.hash(pin);
      final stepUpToken =
          await ref.read(authRepositoryProvider).stepUp(pinHash);
      // Store in memory only — never persisted.
      _inMemoryStepUpToken = stepUpToken;
      if (mounted) context.pop();
    } on DioException catch (e) {
      final code =
          (e.response?.data as Map<String, dynamic>?)?['code'] as String?;
      setState(() {
        _errorMessage = code == 'invalid_credentials'
            ? 'Incorrect PIN — step-up failed'
            : 'Step-up failed. Please try again.';
        _isLoading = false;
      });
    } catch (_) {
      setState(() {
        _errorMessage = 'Step-up failed. Please try again.';
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      // Prevent back navigation while step-up is in progress.
      canPop: !_isLoading,
      child: Scaffold(
        backgroundColor: AppColors.primary,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.lock, size: 64, color: Colors.white),
                const SizedBox(height: AppSpacing.md),
                const Text(
                  'Step-Up Authentication',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                const Text(
                  'This transaction requires additional verification.\nEnter your PIN to continue.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white70, fontSize: 14),
                ),
                const SizedBox(height: AppSpacing.xl),
                if (_isLoading)
                  const CircularProgressIndicator(
                    valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                  )
                else
                  Theme(
                    data: ThemeData.dark(),
                    child: PinPadWidget(
                      onCompleted: _onPinCompleted,
                      errorText: _errorMessage,
                    ),
                  ),
                const SizedBox(height: AppSpacing.xl),
                TextButton(
                  onPressed: _isLoading ? null : () => context.pop(),
                  child: const Text(
                    'Cancel',
                    style: TextStyle(color: Colors.white70),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

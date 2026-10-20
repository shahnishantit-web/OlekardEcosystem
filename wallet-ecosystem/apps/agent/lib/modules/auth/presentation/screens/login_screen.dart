import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/security/biometric_service.dart';
import 'package:wallet_agent/core/security/pin_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/pin_pad_widget.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/core/notifications/push_notification_service.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

final _biometricServiceProvider = Provider((_) => BiometricService());

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phoneController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _isLoading = false;
  String? _errorMessage;
  DateTime? _lockoutUntil;

  @override
  void initState() {
    super.initState();
    _tryBiometric();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _tryBiometric() async {
    final biometric = ref.read(_biometricServiceProvider);
    final available = await biometric.isAvailable();
    if (!available || !mounted) return;
    final success = await biometric.authenticate();
    if (success && mounted) {
      // Biometric success: re-authenticate using stored pin hash
      final pinHash =
          await ref.read(secureStorageServiceProvider).getPinHash();
      if (pinHash != null && mounted) {
        await _submitLogin(pinHash: pinHash);
      }
    }
  }

  Future<void> _onPinCompleted(String pin) async {
    if (_phoneController.text.trim().isEmpty) {
      setState(() => _errorMessage = 'Please enter your phone number first.');
      return;
    }
    final pinHash = PinService.hash(pin);
    await ref.read(secureStorageServiceProvider).savePinHash(pinHash);
    await _submitLogin(pinHash: pinHash);
  }

  Future<void> _submitLogin({required String pinHash}) async {
    if (_isLoading) return;
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      await ref.read(authNotifierProvider.notifier).login(
            phone: _phoneController.text.trim(),
            pinHash: pinHash,
          );
      if (mounted) context.go('/dashboard');
    } on DioException catch (e) {
      final statusCode = e.response?.statusCode;
      final data = e.response?.data as Map<String, dynamic>?;
      if (statusCode == 423) {
        final unlockAt = data?['unlock_at'] as String?;
        setState(() {
          _lockoutUntil =
              unlockAt != null ? DateTime.parse(unlockAt) : null;
          _errorMessage =
              data?['message'] as String? ?? 'Account locked. Try again later.';
        });
      } else {
        final remaining = data?['attempts_remaining'] as int?;
        setState(() {
          _errorMessage = remaining != null && remaining < 3
              ? 'Incorrect PIN — $remaining attempts remaining.'
              : 'Invalid phone or PIN.';
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
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
                      const SizedBox(height: AppSpacing.xl),
                      const Icon(
                        Icons.account_balance_wallet,
                        size: 56,
                        color: AppColors.primary,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        'Agent Login',
                        style:
                            Theme.of(context).textTheme.displayMedium,
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'Enter your phone number and PIN',
                        style: AppTypography.bodyMedium.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.xl),
                      TextFormField(
                        controller: _phoneController,
                        keyboardType: TextInputType.phone,
                        decoration: const InputDecoration(
                          labelText: 'Phone Number',
                          prefixIcon: Icon(Icons.phone),
                          hintText: '+267 XX XXX XXX',
                        ),
                        validator: (v) =>
                            (v == null || v.trim().isEmpty)
                                ? 'Phone number is required'
                                : null,
                      ),
                      const SizedBox(height: AppSpacing.xl),
                      const Text(
                        'Enter your 6-digit PIN',
                        style: AppTypography.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.md),
                      PinPadWidget(
                        onCompleted: _onPinCompleted,
                        errorText: _errorMessage,
                      ),
                      if (_lockoutUntil != null)
                        Padding(
                          padding:
                              const EdgeInsets.only(top: AppSpacing.sm),
                          child: Text(
                            'Locked until ${_lockoutUntil!.toLocal()}',
                            style: const TextStyle(
                              color: AppColors.error,
                              fontSize: 12,
                            ),
                          ),
                        ),
                      const SizedBox(height: AppSpacing.xl),
                      PrimaryButton(
                        label: 'Login',
                        isLoading: _isLoading,
                        onPressed: () {
                          if (_formKey.currentState!.validate()) {
                            // PIN submit triggers via PinPadWidget.onCompleted
                          }
                        },
                      ),
                      const SizedBox(height: AppSpacing.md),
                      Center(
                        child: TextButton(
                          onPressed: () => context.push('/forgot-pin'),
                          child: const Text('Forgot PIN?'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

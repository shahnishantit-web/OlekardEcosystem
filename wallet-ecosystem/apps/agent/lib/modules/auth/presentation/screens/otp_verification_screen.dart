import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

class OtpVerificationScreen extends ConsumerStatefulWidget {
  final String otpReference;
  final String phone;

  const OtpVerificationScreen({
    super.key,
    required this.otpReference,
    required this.phone,
  });

  @override
  ConsumerState<OtpVerificationScreen> createState() =>
      _OtpVerificationScreenState();
}

class _OtpVerificationScreenState
    extends ConsumerState<OtpVerificationScreen> {
  final _otpController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;
  int _secondsRemaining = 60;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _otpController.dispose();
    super.dispose();
  }

  void _startCountdown() {
    _timer?.cancel();
    setState(() => _secondsRemaining = 60);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_secondsRemaining <= 0) {
        t.cancel();
      } else {
        setState(() => _secondsRemaining--);
      }
    });
  }

  Future<void> _resend() async {
    try {
      await ref.read(authRepositoryProvider).forgotPin(widget.phone);
      _startCountdown();
    } on DioException catch (_) {
      setState(() => _errorMessage = 'Failed to resend OTP.');
    }
  }

  Future<void> _submit() async {
    final otp = _otpController.text.trim();
    if (otp.length != 6) {
      setState(() => _errorMessage = 'Enter the 6-digit OTP.');
      return;
    }
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      // Navigate to new PIN screen; actual OTP validation happens on reset-pin submit.
      if (mounted) {
        context.push('/forgot-pin/new-pin', extra: {
          'otp_reference': widget.otpReference,
          'otp_code': otp,
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Verify OTP')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Enter the 6-digit code sent to ${widget.phone}',
                style: AppTypography.bodyMedium),
            const SizedBox(height: AppSpacing.xl),
            TextFormField(
              controller: _otpController,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(
                labelText: 'OTP Code',
                prefixIcon: Icon(Icons.lock_outline),
              ),
            ),
            if (_errorMessage != null)
              Text(
                _errorMessage!,
                style: const TextStyle(color: AppColors.error, fontSize: 13),
              ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Text(
                  _secondsRemaining > 0
                      ? 'Resend in ${_secondsRemaining}s'
                      : 'Didn\'t receive the code?',
                  style: AppTypography.bodySmall,
                ),
                if (_secondsRemaining == 0) ...[
                  const SizedBox(width: AppSpacing.sm),
                  TextButton(
                    onPressed: _resend,
                    child: const Text('Resend'),
                  ),
                ],
              ],
            ),
            const SizedBox(height: AppSpacing.xl),
            PrimaryButton(
              label: 'Verify',
              isLoading: _isLoading,
              onPressed: _submit,
            ),
          ],
        ),
      ),
    );
  }
}

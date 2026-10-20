import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/security/pin_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/pin_pad_widget.dart';
import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

class PinResetScreen extends ConsumerStatefulWidget {
  final String otpReference;
  final String otpCode;

  const PinResetScreen({
    super.key,
    required this.otpReference,
    required this.otpCode,
  });

  @override
  ConsumerState<PinResetScreen> createState() => _PinResetScreenState();
}

class _PinResetScreenState extends ConsumerState<PinResetScreen> {
  String? _firstPin;
  bool _isConfirming = false;
  bool _isLoading = false;
  String? _errorMessage;

  void _onFirstPinCompleted(String pin) {
    setState(() {
      _firstPin = pin;
      _isConfirming = true;
      _errorMessage = null;
    });
  }

  Future<void> _onConfirmPinCompleted(String confirmPin) async {
    if (_firstPin != confirmPin) {
      setState(() {
        _errorMessage = 'PINs do not match. Please try again.';
        _isConfirming = false;
        _firstPin = null;
      });
      return;
    }
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      final newPinHash = PinService.hash(confirmPin);
      await ref.read(authRepositoryProvider).resetPin(
            otpReference: widget.otpReference,
            otpCode: widget.otpCode,
            newPinHash: newPinHash,
          );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('PIN reset successfully. Please log in.')),
        );
        context.go('/login');
      }
    } on DioException catch (e) {
      setState(() {
        _errorMessage = (e.response?.data as Map<String, dynamic>?)?['message']
                as String? ??
            'Failed to reset PIN. OTP may have expired.';
        _isConfirming = false;
        _firstPin = null;
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Set New PIN')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _isConfirming ? 'Confirm your new PIN' : 'Enter a new 6-digit PIN',
              style: Theme.of(context).textTheme.headlineMedium,
            ),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else if (_isConfirming)
              PinPadWidget(
                key: const ValueKey('confirm'),
                onCompleted: _onConfirmPinCompleted,
                errorText: _errorMessage,
              )
            else
              PinPadWidget(
                key: const ValueKey('new'),
                onCompleted: _onFirstPinCompleted,
                errorText: _errorMessage,
              ),
          ],
        ),
      ),
    );
  }
}

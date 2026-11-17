import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';
import '../auth_providers.dart';

class PinResetScreen extends ConsumerStatefulWidget {
  final String? challengeId;

  const PinResetScreen({super.key, required this.challengeId});

  @override
  ConsumerState<PinResetScreen> createState() => _PinResetScreenState();
}

class _PinResetScreenState extends ConsumerState<PinResetScreen> {
  final _otpController = TextEditingController();
  String? _newPin;
  String? _error;
  bool _isLoading = false;

  @override
  void dispose() {
    _otpController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final confirming = _newPin != null;
    return Scaffold(
      appBar: AppBar(title: const Text('Reset PIN')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: ListView(
          children: [
            const Text(
              'Enter the verification code sent to your phone, then choose a new 6-digit PIN.',
              style: AppTypography.body,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextField(
              controller: _otpController,
              keyboardType: TextInputType.number,
              textInputAction: TextInputAction.done,
              decoration: const InputDecoration(labelText: 'Verification code'),
            ),
            const SizedBox(height: AppSpacing.lg),
            Text(
              confirming ? 'Confirm your new PIN' : 'Enter your new PIN',
              style: AppTypography.title,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.md),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(onCompleted: _onPin),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.md),
              Text(_error!, style: const TextStyle(color: AppColors.error)),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _onPin(String pin) async {
    if (_newPin == null) {
      setState(() {
        _newPin = pin;
        _error = null;
      });
      return;
    }

    if (_newPin != pin) {
      setState(() {
        _newPin = null;
        _error = 'PINs do not match. Please enter your new PIN again.';
      });
      return;
    }

    final challengeId = widget.challengeId;
    final otp = _otpController.text.trim();
    if (challengeId == null || otp.isEmpty) {
      setState(() =>
          _error = 'Unable to reset PIN. Request a new code and try again.');
      return;
    }

    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      await ref.read(authRepositoryProvider).completePinReset(
            challengeId: challengeId,
            otp: otp,
            newPin: pin,
          );
      if (mounted) {
        setState(() {
          _newPin = null;
          _otpController.clear();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content:
                  Text('PIN updated successfully. Sign in with your new PIN.')),
        );
        context.go('/login');
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _newPin = null;
          _error = 'PIN reset failed. Check the code and try again.';
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
}

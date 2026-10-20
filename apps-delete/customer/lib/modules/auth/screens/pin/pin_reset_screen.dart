import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';
import 'pin_keypad.dart';

/// US-AUTH-030 — PIN reset via OTP.
/// Handles two server calls: initiate (mobile → OTP challenge) and complete
/// (challenge_id + otp + new_pin). OTP entry is handled inline to avoid
/// a separate route for this flow.
class PinResetScreen extends ConsumerStatefulWidget {
  const PinResetScreen({super.key});

  @override
  ConsumerState<PinResetScreen> createState() => _PinResetScreenState();
}

enum _ResetPhase { mobileEntry, otpEntry, newPin, confirmPin }

class _PinResetScreenState extends ConsumerState<PinResetScreen> {
  static const _pinLength = 6;
  _ResetPhase _phase = _ResetPhase.mobileEntry;
  final _mobileController = TextEditingController();
  final _otpController = TextEditingController();
  String _newPin = '';
  String _pinBuffer = '';
  String? _localError;

  @override
  void dispose() {
    _mobileController.dispose();
    _otpController.dispose();
    super.dispose();
  }

  void _initiate() {
    final mobile = _mobileController.text.trim();
    if (mobile.isEmpty) return;
    ref
        .read(authStateNotifierProvider.notifier)
        .initiatePinReset(mobile);
    setState(() => _phase = _ResetPhase.otpEntry);
  }

  void _complete() {
    final challengeId =
        (ref.read(authStateNotifierProvider).valueOrNull as AuthOtpPending?)
            ?.challengeId;
    if (challengeId == null) return;
    ref
        .read(authStateNotifierProvider.notifier)
        .completePinReset(challengeId, _otpController.text.trim(), _newPin);
  }

  void _onDigit(String digit) {
    if (_pinBuffer.length >= _pinLength) return;
    setState(() {
      _pinBuffer += digit;
      _localError = null;
    });
    if (_pinBuffer.length == _pinLength) _advancePin();
  }

  void _onBackspace() {
    if (_pinBuffer.isEmpty) return;
    setState(() =>
        _pinBuffer = _pinBuffer.substring(0, _pinBuffer.length - 1));
  }

  void _advancePin() {
    if (_phase == _ResetPhase.newPin) {
      setState(() {
        _newPin = _pinBuffer;
        _pinBuffer = '';
        _phase = _ResetPhase.confirmPin;
      });
    } else if (_phase == _ResetPhase.confirmPin) {
      if (_pinBuffer != _newPin) {
        setState(() {
          _localError = 'PINs do not match. Try again.';
          _newPin = '';
          _pinBuffer = '';
          _phase = _ResetPhase.newPin;
        });
      } else {
        _complete();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final apiError = switch (authAsync.valueOrNull) {
      AuthOtpPending(:final errorMessage) => errorMessage,
      AuthUnauthenticated(:final errorMessage) => errorMessage,
      _ => null,
    };
    final displayError = _localError ?? apiError;

    return Scaffold(
      appBar: AppBar(title: const Text('Reset PIN')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isLoading
              ? Center(
                  child: LoadingWidget(
                    message: _phase == _ResetPhase.mobileEntry
                        ? 'Sending code…'
                        : 'Resetting PIN…',
                  ),
                )
              : _buildPhase(context, displayError),
        ),
      ),
    );
  }

  Widget _buildPhase(BuildContext context, String? error) {
    return switch (_phase) {
      _ResetPhase.mobileEntry => _MobileEntry(
          controller: _mobileController,
          errorMessage: error,
          onSubmit: _initiate,
        ),
      _ResetPhase.otpEntry => _OtpEntry(
          controller: _otpController,
          errorMessage: error,
          onSubmit: () => setState(() => _phase = _ResetPhase.newPin),
        ),
      _ResetPhase.newPin => _PinPhase(
          title: 'Enter new PIN',
          buffer: _pinBuffer,
          error: error,
          onDigit: _onDigit,
          onBackspace: _onBackspace,
        ),
      _ResetPhase.confirmPin => _PinPhase(
          title: 'Confirm new PIN',
          buffer: _pinBuffer,
          error: error,
          onDigit: _onDigit,
          onBackspace: _onBackspace,
        ),
    };
  }
}

class _MobileEntry extends StatelessWidget {
  const _MobileEntry({
    required this.controller,
    required this.errorMessage,
    required this.onSubmit,
  });

  final TextEditingController controller;
  final String? errorMessage;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Enter your registered mobile number to receive a reset code.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 24),
        TextField(
          controller: controller,
          keyboardType: TextInputType.phone,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'Mobile number',
            hintText: '+267 7X XXX XXX',
            border: const OutlineInputBorder(),
            errorText: errorMessage,
          ),
          onSubmitted: (_) => onSubmit(),
        ),
        const SizedBox(height: 24),
        AppButton(label: 'Send Reset Code', onPressed: onSubmit),
      ],
    );
  }
}

class _OtpEntry extends StatelessWidget {
  const _OtpEntry({
    required this.controller,
    required this.errorMessage,
    required this.onSubmit,
  });

  final TextEditingController controller;
  final String? errorMessage;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Enter the 6-digit code sent to your mobile number.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 24),
        TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'Verification code',
            border: const OutlineInputBorder(),
            errorText: errorMessage,
          ),
        ),
        const SizedBox(height: 24),
        AppButton(label: 'Continue', onPressed: onSubmit),
      ],
    );
  }
}

class _PinPhase extends StatelessWidget {
  const _PinPhase({
    required this.title,
    required this.buffer,
    required this.error,
    required this.onDigit,
    required this.onBackspace,
  });

  final String title;
  final String buffer;
  final String? error;
  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Semantics(
          header: true,
          child: Text(title,
              style: Theme.of(context).textTheme.headlineSmall),
        ),
        const SizedBox(height: 32),
        Semantics(
          label: '${buffer.length} of 6 digits entered',
          child: PinDots(length: 6, filled: buffer.length, hasError: error != null),
        ),
        if (error != null) ...[
          const SizedBox(height: 12),
          Semantics(
            liveRegion: true,
            child: Text(
              error!,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: Theme.of(context).colorScheme.error),
              textAlign: TextAlign.center,
            ),
          ),
        ],
        const Spacer(),
        PinKeypad(onDigit: onDigit, onBackspace: onBackspace),
      ],
    );
  }
}

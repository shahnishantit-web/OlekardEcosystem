import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-032, US-AUTH-033 — PIN unblock via SMS OTP.
/// Two-step flow: mobile number entry → OTP verification → account unlocked.
class PinUnblockScreen extends ConsumerStatefulWidget {
  const PinUnblockScreen({super.key});

  @override
  ConsumerState<PinUnblockScreen> createState() => _PinUnblockScreenState();
}

class _PinUnblockScreenState extends ConsumerState<PinUnblockScreen> {
  final _mobileController = TextEditingController();
  final _otpController = TextEditingController();
  bool _completed = false;
  bool _unblockInitiated = false;

  @override
  void dispose() {
    _mobileController.dispose();
    _otpController.dispose();
    super.dispose();
  }

  void _initiate() {
    final mobile = _mobileController.text.trim();
    if (mobile.isEmpty) return;
    _unblockInitiated = true;
    ref.read(authStateNotifierProvider.notifier).initiatePinUnblock(mobile);
  }

  void _complete() {
    final unblockState =
        ref.read(authStateNotifierProvider).valueOrNull as AuthPinUnblockPending?;
    if (unblockState == null) return;
    ref
        .read(authStateNotifierProvider.notifier)
        .completePinUnblock(unblockState.challengeId, _otpController.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final currentState = authAsync.valueOrNull;

    ref.listen<AsyncValue<AuthState>>(authStateNotifierProvider, (_, next) {
      if (!mounted) return;
      final value = next.valueOrNull;
      if (value is AuthUnauthenticated && !value.hasError && _unblockInitiated) {
        setState(() => _completed = true);
        Future.delayed(const Duration(seconds: 2), () {
          if (mounted) context.go('/auth/register');
        });
      }
    });

    final isLoading = authAsync.isLoading;
    final errorMessage = switch (currentState) {
      AuthPinUnblockPending(:final errorMessage) => errorMessage,
      AuthUnauthenticated(:final errorMessage) => errorMessage,
      _ => null,
    };

    final inOtpPhase = currentState is AuthPinUnblockPending;

    return Scaffold(
      appBar: AppBar(title: const Text('Recover Account')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isLoading
              ? Center(
                  child: LoadingWidget(
                    message: inOtpPhase ? 'Verifying…' : 'Sending unlock code…',
                  ),
                )
              : _buildPhase(context, errorMessage, inOtpPhase),
        ),
      ),
    );
  }

  Widget _buildPhase(BuildContext context, String? error, bool inOtpPhase) {
    if (_completed) return const _SuccessView();
    if (inOtpPhase) {
      return _OtpEntry(
        controller: _otpController,
        errorMessage: error,
        onSubmit: _complete,
      );
    }
    return _MobileEntry(
      controller: _mobileController,
      errorMessage: error,
      onSubmit: _initiate,
    );
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
        Semantics(
          header: true,
          child: Text(
            'Recover locked account',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Enter your registered mobile number to receive an unlock code.',
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
        AppButton(label: 'Send Unlock Code', onPressed: onSubmit),
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
        Semantics(
          header: true,
          child: Text(
            'Enter unlock code',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Enter the 6-digit code sent to your mobile number.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 24),
        Semantics(
          label: 'Unlock code, 6 digits',
          child: TextField(
            controller: controller,
            keyboardType: TextInputType.number,
            maxLength: 6,
            autofocus: true,
            decoration: InputDecoration(
              labelText: 'Unlock code',
              border: const OutlineInputBorder(),
              errorText: errorMessage,
            ),
            onChanged: (v) {
              if (v.length == 6) onSubmit();
            },
          ),
        ),
        const SizedBox(height: 24),
        AppButton(label: 'Verify', onPressed: onSubmit),
      ],
    );
  }
}

class _SuccessView extends StatelessWidget {
  const _SuccessView();

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          liveRegion: true,
          child: Icon(
            Icons.check_circle_outline,
            size: 72,
            color: Theme.of(context).colorScheme.primary,
            semanticLabel: 'Account unlocked',
          ),
        ),
        const SizedBox(height: 24),
        Semantics(
          header: true,
          child: Text(
            'Account Unlocked',
            style: Theme.of(context).textTheme.headlineMedium,
            textAlign: TextAlign.center,
          ),
        ),
        const SizedBox(height: 12),
        Text(
          'Your PIN has been unblocked. You can now sign in.',
          style: Theme.of(context).textTheme.bodyMedium,
          textAlign: TextAlign.center,
        ),
      ],
    );
  }
}

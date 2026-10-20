import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-003 / US-AUTH-004 — OTP verification screen.
/// 6-digit numeric input with auto-submit and resend countdown.
class OtpVerifyScreen extends ConsumerStatefulWidget {
  const OtpVerifyScreen({super.key});

  @override
  ConsumerState<OtpVerifyScreen> createState() => _OtpVerifyScreenState();
}

class _OtpVerifyScreenState extends ConsumerState<OtpVerifyScreen> {
  final _controller = TextEditingController();
  Timer? _resendTimer;
  Duration _resendCountdown = Duration.zero;

  @override
  void initState() {
    super.initState();
    _startResendTimer();
  }

  @override
  void dispose() {
    _controller.dispose();
    _resendTimer?.cancel();
    super.dispose();
  }

  void _startResendTimer() {
    final st = ref.read(authStateNotifierProvider).valueOrNull;
    if (st is! AuthOtpPending) return;
    final resendAt = st.resendAt;
    if (resendAt == null) return;
    _updateCountdown(resendAt);
    _resendTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      _updateCountdown(resendAt);
    });
  }

  void _updateCountdown(DateTime resendAt) {
    final remaining = resendAt.difference(DateTime.now());
    if (remaining.isNegative) {
      _resendTimer?.cancel();
      if (mounted) setState(() => _resendCountdown = Duration.zero);
    } else {
      if (mounted) setState(() => _resendCountdown = remaining);
    }
  }

  void _onOtpChanged(String value) {
    if (value.length == 6) _submit(value);
  }

  void _submit(String otp) {
    final st = ref.read(authStateNotifierProvider).valueOrNull;
    if (st is! AuthOtpPending) return;
    FocusScope.of(context).unfocus();
    ref.read(authStateNotifierProvider.notifier).verifyOtp(st.challengeId, otp);
  }

  void _resend() {
    final st = ref.read(authStateNotifierProvider).valueOrNull;
    if (st is! AuthOtpPending) return;
    _controller.clear();
    ref.read(authStateNotifierProvider.notifier).resendOtp(st.challengeId);
    setState(() => _resendCountdown = const Duration(seconds: 60));
    _resendTimer?.cancel();
    _resendTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      final updated = ref.read(authStateNotifierProvider).valueOrNull;
      if (updated is AuthOtpPending && updated.resendAt != null) {
        _updateCountdown(updated.resendAt!);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final otpState = authAsync.valueOrNull is AuthOtpPending
        ? authAsync.valueOrNull as AuthOtpPending
        : null;
    final errorMessage = otpState?.errorMessage;
    final maskedDest = otpState?.maskedDestination;
    final resendAt = otpState?.resendAt;
    final inCooldown = resendAt != null && DateTime.now().isBefore(resendAt);
    final canResend = !inCooldown && !isLoading;

    return Scaffold(
      appBar: AppBar(title: const Text('Verify Code')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isLoading
              ? const Center(child: LoadingWidget(message: 'Verifying…'))
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        'Enter the 6-digit code',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 8),
                    if (maskedDest != null)
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          'Code sent to $maskedDest',
                          style: Theme.of(context)
                              .textTheme
                              .bodyMedium
                              ?.copyWith(
                                color: Theme.of(context)
                                    .colorScheme
                                    .onSurfaceVariant,
                              ),
                        ),
                      ),
                    const SizedBox(height: 32),
                    Semantics(
                      label: 'One-time code, 6 digits',
                      child: _OtpInputRow(
                        controller: _controller,
                        onChanged: _onOtpChanged,
                        hasError: errorMessage != null,
                      ),
                    ),
                    if (errorMessage != null) ...[
                      const SizedBox(height: 12),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          errorMessage,
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color:
                                        Theme.of(context).colorScheme.error,
                                  ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    const SizedBox(height: 32),
                    AppButton(
                      label: 'Verify',
                      onPressed: isLoading
                          ? null
                          : () => _submit(_controller.text),
                    ),
                    const SizedBox(height: 16),
                    _ResendButton(
                      countdown: _resendCountdown,
                      canResend: canResend,
                      onResend: _resend,
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _OtpInputRow extends StatelessWidget {
  const _OtpInputRow({
    required this.controller,
    required this.onChanged,
    required this.hasError,
  });

  final TextEditingController controller;
  final ValueChanged<String> onChanged;
  final bool hasError;

  @override
  Widget build(BuildContext context) {
    return Stack(
      alignment: Alignment.center,
      children: [
        Opacity(
          opacity: 0,
          child: SizedBox(
            width: 1,
            height: 1,
            child: TextField(
              controller: controller,
              autofocus: true,
              keyboardType: TextInputType.number,
              maxLength: 6,
              onChanged: onChanged,
              decoration: const InputDecoration(counterText: ''),
            ),
          ),
        ),
        ValueListenableBuilder(
          valueListenable: controller,
          builder: (context, value, _) => Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(6, (i) {
              final digit =
                  i < controller.text.length ? controller.text[i] : '';
              final active = i == controller.text.length;
              return GestureDetector(
                onTap: () => FocusScope.of(context).requestFocus(FocusNode()),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 120),
                  width: 44,
                  height: 56,
                  margin: const EdgeInsets.symmetric(horizontal: 4),
                  decoration: BoxDecoration(
                    border: Border.all(
                      color: hasError
                          ? Theme.of(context).colorScheme.error
                          : active
                              ? Theme.of(context).colorScheme.primary
                              : Theme.of(context).colorScheme.outline,
                      width: active ? 2 : 1,
                    ),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    digit.isEmpty ? '' : '•',
                    style: Theme.of(context).textTheme.headlineMedium,
                  ),
                ),
              );
            }),
          ),
        ),
      ],
    );
  }
}

class _ResendButton extends StatelessWidget {
  const _ResendButton({
    required this.countdown,
    required this.canResend,
    required this.onResend,
  });

  final Duration countdown;
  final bool canResend;
  final VoidCallback onResend;

  @override
  Widget build(BuildContext context) {
    final label =
        canResend ? 'Resend code' : 'Resend in ${countdown.inSeconds}s';
    return Semantics(
      liveRegion: !canResend,
      child: TextButton(
        onPressed: canResend ? onResend : null,
        child: Text(label),
      ),
    );
  }
}

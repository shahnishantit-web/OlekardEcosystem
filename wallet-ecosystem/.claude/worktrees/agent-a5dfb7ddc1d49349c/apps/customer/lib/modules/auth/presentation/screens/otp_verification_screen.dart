import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';

class OtpVerificationScreen extends ConsumerStatefulWidget {
  const OtpVerificationScreen({super.key});

  @override
  ConsumerState<OtpVerificationScreen> createState() => _OtpVerificationScreenState();
}

class _OtpVerificationScreenState extends ConsumerState<OtpVerificationScreen> {
  late Timer _timer;
  int _secondsLeft = 60;
  bool _canResend = false;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  @override
  void dispose() {
    _timer.cancel();
    super.dispose();
  }

  void _startCountdown() {
    _secondsLeft = 60;
    _canResend = false;
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_secondsLeft == 0) {
        t.cancel();
        setState(() => _canResend = true);
      } else {
        setState(() => _secondsLeft--);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authProvider, (_, next) {
      if (next.status == AuthStateEnum.profileSetup) {
        context.go('/register/profile');
      }
      if (next.isAuthenticated) context.go('/home');
      if (next.error != null) {
        ScaffoldMessenger.of(context)
          ..hideCurrentSnackBar()
          ..showSnackBar(SnackBar(content: Text(next.error!)));
      }
    });

    return Scaffold(
      appBar: AppBar(title: const Text('Verify OTP')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: AppSpacing.xl),
            const Text(
              'Enter the 6-digit code sent to your phone',
              style: AppTypography.body,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.xl),
            Semantics(
              label: 'One-time password entry',
              child: PinPadWidget(
                length: 6,
                onCompleted: (otp) {
                  ref.read(authProvider.notifier).verifyOtp(otp);
                },
              ),
            ),
            const SizedBox(height: AppSpacing.xl),
            if (_canResend)
              TextButton(
                onPressed: _resendOtp,
                child: const Text('Resend OTP'),
              )
            else
              Text(
                'Resend in :${_secondsLeft.toString().padLeft(2, '0')}',
                textAlign: TextAlign.center,
                style: AppTypography.label.copyWith(color: Colors.grey),
              ),
          ],
        ),
      ),
    );
  }

  void _resendOtp() {
    // Resend is handled by re-initiating registration (phone stored in notifier)
    _startCountdown();
  }
}

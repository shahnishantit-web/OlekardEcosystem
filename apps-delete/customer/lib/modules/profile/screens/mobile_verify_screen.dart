import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-006 — Mobile change OTP verification.
/// Mirrors the email verify screen with mobile-specific labels.
class MobileVerifyScreen extends ConsumerStatefulWidget {
  const MobileVerifyScreen({super.key});

  @override
  ConsumerState<MobileVerifyScreen> createState() => _MobileVerifyScreenState();
}

class _MobileVerifyScreenState extends ConsumerState<MobileVerifyScreen> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    if (value.length == 6) _submit(value);
  }

  void _submit(String otp) {
    FocusScope.of(context).unfocus();
    ref.read(profileStateNotifierProvider.notifier).verifyMobileChange(otp);
  }

  @override
  Widget build(BuildContext context) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final challenge = loaded?.contactChallenge;
    final isSubmitting = loaded?.isSubmitting ?? profileAsync.isLoading;
    final errorMessage = challenge?.errorMessage;
    final errorCode = challenge?.errorCode;
    final masked = challenge?.maskedDestination;

    return Scaffold(
      appBar: AppBar(title: const Text('Verify Mobile Number')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isSubmitting
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
                    if (masked != null)
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          'SMS sent to $masked',
                          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                color: Theme.of(context)
                                    .colorScheme
                                    .onSurfaceVariant,
                              ),
                        ),
                      ),
                    const SizedBox(height: 32),
                    Semantics(
                      label: 'One-time code, 6 digits',
                      child: TextFormField(
                        controller: _controller,
                        autofocus: true,
                        keyboardType: TextInputType.number,
                        maxLength: 6,
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 24, letterSpacing: 8),
                        decoration: InputDecoration(
                          counterText: '',
                          hintText: '000000',
                          border: const OutlineInputBorder(),
                          errorText: errorMessage != null
                              ? _friendlyError(errorCode, errorMessage)
                              : null,
                        ),
                        onChanged: _onChanged,
                      ),
                    ),
                    if (errorCode == 'OTP_EXPIRED' ||
                        errorCode == 'CHALLENGE_NOT_FOUND') ...[
                      const SizedBox(height: 16),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          'The code has expired. Go back and request a new one.',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: Theme.of(context).colorScheme.error,
                              ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    const SizedBox(height: 32),
                    AppButton(
                      label: 'Verify',
                      onPressed: isSubmitting
                          ? null
                          : () => _submit(_controller.text),
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: const Text('Use a different number'),
                    ),
                  ],
                ),
        ),
      ),
    );
  }

  String _friendlyError(String? code, String message) => switch (code) {
        'OTP_INVALID' => 'Incorrect code. Please try again.',
        'OTP_EXPIRED' => 'Code has expired.',
        'CHALLENGE_NOT_FOUND' => 'Session not found. Go back and start over.',
        _ => message,
      };
}

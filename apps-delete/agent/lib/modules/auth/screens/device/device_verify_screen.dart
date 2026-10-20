import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-016, US-AUTH-017 — New device OTP verification.
/// Shown when login detects an unrecognised device fingerprint.
class DeviceVerifyScreen extends ConsumerStatefulWidget {
  const DeviceVerifyScreen({super.key});

  @override
  ConsumerState<DeviceVerifyScreen> createState() => _DeviceVerifyScreenState();
}

class _DeviceVerifyScreenState extends ConsumerState<DeviceVerifyScreen> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onOtpChanged(String value) {
    if (value.length == 6) _submit();
  }

  void _submit() {
    final st = ref.read(authStateNotifierProvider).valueOrNull;
    if (st is! AuthDeviceVerificationRequired) return;
    FocusScope.of(context).unfocus();
    ref
        .read(authStateNotifierProvider.notifier)
        .verifyDevice(st.challengeId, _controller.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final devState = authAsync.valueOrNull is AuthDeviceVerificationRequired
        ? authAsync.valueOrNull as AuthDeviceVerificationRequired
        : null;
    final errorMessage = devState?.errorMessage;

    return Scaffold(
      appBar: AppBar(title: const Text('Verify Device')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isLoading
              ? const Center(child: LoadingWidget(message: 'Verifying device…'))
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        'New device detected',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'We sent a 6-digit code to your registered mobile number '
                      'to verify this device.',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant,
                          ),
                    ),
                    const SizedBox(height: 32),
                    Semantics(
                      label: 'Device verification code, 6 digits',
                      child: TextField(
                        controller: _controller,
                        keyboardType: TextInputType.number,
                        maxLength: 6,
                        autofocus: true,
                        decoration: InputDecoration(
                          labelText: 'Verification code',
                          border: const OutlineInputBorder(),
                          errorText: errorMessage,
                        ),
                        onChanged: _onOtpChanged,
                      ),
                    ),
                    if (errorMessage != null) ...[
                      const SizedBox(height: 8),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          errorMessage,
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(
                                  color: Theme.of(context).colorScheme.error),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    const SizedBox(height: 24),
                    AppButton(
                      label: 'Verify Device',
                      onPressed: isLoading ? null : _submit,
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

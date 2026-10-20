import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../services/biometric_assertion_client.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-012 — Biometric authentication screen.
/// Triggers device biometric prompt; submits signed assertion (NOT raw biometric).
/// TBD-004 abstraction preserved: the [BiometricAssertionClient] interface is
/// the sole coupling point. Raw biometric data MUST NOT be sent to the server.
class BiometricScreen extends ConsumerStatefulWidget {
  const BiometricScreen({
    super.key,
    required this.userId,
    required this.credentialId,
    required this.deviceFingerprint,
  });

  final String userId;
  final String credentialId;
  final String deviceFingerprint;

  @override
  ConsumerState<BiometricScreen> createState() => _BiometricScreenState();
}

class _BiometricScreenState extends ConsumerState<BiometricScreen> {
  bool _triggering = false;
  String? _error;

  BiometricAssertionClient get _biometricClient =>
      ref.read(biometricAssertionClientProvider);

  @override
  void initState() {
    super.initState();
    // Auto-trigger on screen open.
    WidgetsBinding.instance.addPostFrameCallback((_) => _authenticate());
  }

  Future<void> _authenticate() async {
    setState(() {
      _triggering = true;
      _error = null;
    });

    final result = await _biometricClient.authenticate(
      nonce: '',  // Nonce fetched inside loginBiometric.
      userId: widget.userId,
      credentialId: widget.credentialId,
    );

    if (!mounted) return;

    if (!result.success || result.signedAssertion == null) {
      setState(() {
        _triggering = false;
        _error = result.failureReason ?? 'Biometric authentication failed.';
      });
      return;
    }

    setState(() => _triggering = false);
    await ref.read(authStateNotifierProvider.notifier).loginBiometric(
          userId: widget.userId,
          credentialId: widget.credentialId,
          signedAssertion: result.signedAssertion!,
          deviceFingerprint: widget.deviceFingerprint,
        );
  }

  @override
  Widget build(BuildContext context) {
    final isLoading =
        _triggering || ref.watch(authStateNotifierProvider).isLoading;

    return Scaffold(
      appBar: AppBar(title: const Text('Biometric Sign In')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (isLoading) ...[
                const LoadingWidget(message: 'Authenticating…'),
              ] else ...[
                Semantics(
                  label: 'Fingerprint icon',
                  child: Icon(
                    Icons.fingerprint,
                    size: 80,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  'Use your biometric to sign in',
                  style: Theme.of(context).textTheme.headlineSmall,
                  textAlign: TextAlign.center,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 16),
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      _error!,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context).colorScheme.error,
                          ),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
                const SizedBox(height: 32),
                AppButton(
                  label: 'Try again',
                  onPressed: _authenticate,
                ),
                const SizedBox(height: 12),
                TextButton(
                  onPressed: () =>
                      Navigator.of(context).pushReplacementNamed('/auth/login'),
                  child: const Text('Use PIN instead'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Provider for the biometric client. Override with a real SDK when TBD-004 resolves.
final biometricAssertionClientProvider =
    Provider<BiometricAssertionClient>((ref) {
  return const StubBiometricAssertionClient();
});

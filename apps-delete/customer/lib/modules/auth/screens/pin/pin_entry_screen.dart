import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';
import 'pin_keypad.dart';

/// US-AUTH-010 — PIN entry screen for login.
/// Numeric keypad; PIN displayed as obscured dots; delegates login to notifier.
class PinEntryScreen extends ConsumerStatefulWidget {
  const PinEntryScreen({super.key, required this.identifier});

  final String identifier;

  @override
  ConsumerState<PinEntryScreen> createState() => _PinEntryScreenState();
}

class _PinEntryScreenState extends ConsumerState<PinEntryScreen> {
  static const _pinLength = 6;
  final _buffer = StringBuffer();

  void _onDigit(String digit) {
    if (_buffer.length >= _pinLength) return;
    setState(() => _buffer.write(digit));
    if (_buffer.length == _pinLength) _submit();
  }

  void _onBackspace() {
    if (_buffer.isEmpty) return;
    final str = _buffer.toString();
    setState(() {
      _buffer.clear();
      _buffer.write(str.substring(0, str.length - 1));
    });
  }

  void _submit() {
    // Device fingerprint is collected separately; stub with empty string here.
    // The real fingerprint collection belongs in the device service (Phase 3).
    ref.read(authStateNotifierProvider.notifier).login(
          identifier: widget.identifier,
          credential: _buffer.toString(),
          deviceFingerprint: '',
        );
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final errorMessage = switch (authAsync.valueOrNull) {
      AuthUnauthenticated(:final errorMessage) => errorMessage,
      _ => null,
    };

    // Clear PIN buffer on error so the user starts fresh.
    if (errorMessage != null && _buffer.isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        setState(() => _buffer.clear());
      });
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Enter PIN')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 24),
          child: isLoading
              ? const Center(child: LoadingWidget(message: 'Signing in…'))
              : Column(
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        'Enter your PIN',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 32),
                    Semantics(
                      label: '${_buffer.length} of $_pinLength digits entered',
                      child: PinDots(
                        length: _pinLength,
                        filled: _buffer.length,
                        hasError: errorMessage != null,
                      ),
                    ),
                    if (errorMessage != null) ...[
                      const SizedBox(height: 12),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          errorMessage,
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: Theme.of(context).colorScheme.error,
                              ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    const Spacer(),
                    PinKeypad(
                      onDigit: _onDigit,
                      onBackspace: _onBackspace,
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () =>
                          Navigator.of(context).pushNamed('/auth/pin/reset'),
                      child: const Text('Forgot PIN?'),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

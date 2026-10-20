import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';
import 'pin_keypad.dart';

/// US-AUTH-010 — PIN entry screen for Agent login.
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
    final errorState = authAsync.valueOrNull is AuthUnauthenticated
        ? authAsync.valueOrNull as AuthUnauthenticated
        : null;
    final errorMessage = errorState?.errorMessage;

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
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(
                                color: Theme.of(context).colorScheme.error,
                              ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ],
                    if (errorState?.isOffline == true) ...[
                      const SizedBox(height: 8),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.wifi_off,
                              size: 14,
                              color: Theme.of(context).colorScheme.error),
                          const SizedBox(width: 4),
                          Text(
                            'Offline',
                            style: Theme.of(context)
                                .textTheme
                                .labelSmall
                                ?.copyWith(
                                  color: Theme.of(context).colorScheme.error,
                                ),
                          ),
                        ],
                      ),
                    ],
                    const Spacer(),
                    PinKeypad(
                      onDigit: _onDigit,
                      onBackspace: _onBackspace,
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: () => context.push('/auth/pin/reset'),
                      child: const Text('Forgot PIN?'),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';
import 'pin_keypad.dart';

/// US-AUTH-029 — Change PIN for an authenticated agent.
/// Three phases: current PIN → new PIN → confirm new PIN.
class PinChangeScreen extends ConsumerStatefulWidget {
  const PinChangeScreen({super.key});

  @override
  ConsumerState<PinChangeScreen> createState() => _PinChangeScreenState();
}

enum _Phase { current, newPin, confirm }

class _PinChangeScreenState extends ConsumerState<PinChangeScreen> {
  static const _pinLength = 6;
  _Phase _phase = _Phase.current;
  String _currentPin = '';
  String _newPin = '';
  String _buffer = '';
  String? _errorMessage;

  void _onDigit(String digit) {
    if (_buffer.length >= _pinLength) return;
    setState(() {
      _buffer += digit;
      _errorMessage = null;
    });
    if (_buffer.length == _pinLength) _advance();
  }

  void _onBackspace() {
    if (_buffer.isEmpty) return;
    setState(() => _buffer = _buffer.substring(0, _buffer.length - 1));
  }

  void _advance() {
    switch (_phase) {
      case _Phase.current:
        setState(() {
          _currentPin = _buffer;
          _buffer = '';
          _phase = _Phase.newPin;
        });
      case _Phase.newPin:
        setState(() {
          _newPin = _buffer;
          _buffer = '';
          _phase = _Phase.confirm;
        });
      case _Phase.confirm:
        if (_buffer != _newPin) {
          setState(() {
            _errorMessage = 'PINs do not match. Please try again.';
            _newPin = '';
            _buffer = '';
            _phase = _Phase.newPin;
          });
        } else {
          ref
              .read(authStateNotifierProvider.notifier)
              .changePin(_currentPin, _buffer);
        }
    }
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final apiError = switch (authAsync.valueOrNull) {
      AuthAuthenticated() => null,
      AuthUnauthenticated(:final errorMessage) => errorMessage,
      _ => null,
    };
    final displayError = _errorMessage ?? apiError;

    final subtitle = switch (_phase) {
      _Phase.current => 'Enter your current PIN',
      _Phase.newPin => 'Enter your new PIN',
      _Phase.confirm => 'Confirm your new PIN',
    };

    return Scaffold(
      appBar: AppBar(title: const Text('Change PIN')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 24),
          child: isLoading
              ? const Center(child: LoadingWidget(message: 'Updating PIN…'))
              : Column(
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        subtitle,
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 32),
                    Semantics(
                      label: '${_buffer.length} of $_pinLength digits entered',
                      child: PinDots(
                        length: _pinLength,
                        filled: _buffer.length,
                        hasError: displayError != null,
                      ),
                    ),
                    if (displayError != null) ...[
                      const SizedBox(height: 12),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          displayError,
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
                    const Spacer(),
                    PinKeypad(
                      onDigit: _onDigit,
                      onBackspace: _onBackspace,
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

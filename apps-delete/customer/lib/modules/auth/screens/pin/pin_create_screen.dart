import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';
import 'pin_keypad.dart';

/// US-AUTH-028 — Create (set) a new PIN after successful OTP verification.
/// Two-phase: entry → confirmation. Both phases must match.
class PinCreateScreen extends ConsumerStatefulWidget {
  const PinCreateScreen({super.key});

  @override
  ConsumerState<PinCreateScreen> createState() => _PinCreateScreenState();
}

enum _Phase { entry, confirm }

class _PinCreateScreenState extends ConsumerState<PinCreateScreen> {
  static const _pinLength = 6;
  _Phase _phase = _Phase.entry;
  String _first = '';
  String _current = '';
  String? _errorMessage;

  void _onDigit(String digit) {
    if (_current.length >= _pinLength) return;
    setState(() {
      _current += digit;
      _errorMessage = null;
    });
    if (_current.length == _pinLength) _advance();
  }

  void _onBackspace() {
    if (_current.isEmpty) return;
    setState(() => _current = _current.substring(0, _current.length - 1));
  }

  void _advance() {
    if (_phase == _Phase.entry) {
      setState(() {
        _first = _current;
        _current = '';
        _phase = _Phase.confirm;
      });
    } else {
      if (_current != _first) {
        setState(() {
          _errorMessage = 'PINs do not match. Please try again.';
          _first = '';
          _current = '';
          _phase = _Phase.entry;
        });
      } else {
        _submit(_current);
      }
    }
  }

  void _submit(String pin) {
    ref.read(authStateNotifierProvider.notifier).setPin(pin);
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final apiError = switch (authAsync.valueOrNull) {
      AuthPinPending(:final errorMessage) => errorMessage,
      _ => null,
    };
    final displayError = _errorMessage ?? apiError;

    final title = _phase == _Phase.entry ? 'Create PIN' : 'Confirm PIN';
    final subtitle = _phase == _Phase.entry
        ? 'Choose a 6-digit PIN you will remember.'
        : 'Enter your PIN again to confirm.';

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 32, horizontal: 24),
          child: isLoading
              ? const Center(child: LoadingWidget(message: 'Setting PIN…'))
              : Column(
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        title,
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      subtitle,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant,
                          ),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 32),
                    Semantics(
                      label:
                          '${_current.length} of $_pinLength digits entered',
                      child: PinDots(
                        length: _pinLength,
                        filled: _current.length,
                        hasError: displayError != null,
                      ),
                    ),
                    if (displayError != null) ...[
                      const SizedBox(height: 12),
                      Semantics(
                        liveRegion: true,
                        child: Text(
                          displayError,
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color:
                                        Theme.of(context).colorScheme.error,
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

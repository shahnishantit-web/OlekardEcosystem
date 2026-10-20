import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-035 — MFA verification during login.
/// Shown when login returns mfa_required: true and the user holds an mfa_token.
class MfaVerifyScreen extends ConsumerStatefulWidget {
  const MfaVerifyScreen({super.key});

  @override
  ConsumerState<MfaVerifyScreen> createState() => _MfaVerifyScreenState();
}

class _MfaVerifyScreenState extends ConsumerState<MfaVerifyScreen> {
  final _codeController = TextEditingController();
  String _selectedMethod = 'totp';

  @override
  void dispose() {
    _codeController.dispose();
    super.dispose();
  }

  void _submit() {
    final mfaState = ref.read(authStateNotifierProvider).valueOrNull;
    if (mfaState is! AuthMfaPending) return;

    final code = _codeController.text.trim();
    if (code.isEmpty) return;

    ref.read(authStateNotifierProvider.notifier).verifyMfa(
          mfaToken: mfaState.mfaToken,
          method: _selectedMethod,
          code: code,
        );
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;
    final mfaState =
        authAsync.valueOrNull is AuthMfaPending ? authAsync.valueOrNull as AuthMfaPending : null;
    final errorMessage = mfaState?.errorMessage;
    final methods = mfaState?.methods ?? [_selectedMethod];

    return Scaffold(
      appBar: AppBar(title: const Text('Two-Factor Verification')),
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
                        'Enter verification code',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Open your authenticator app and enter the 6-digit code.',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant,
                          ),
                    ),
                    if (methods.length > 1) ...[
                      const SizedBox(height: 16),
                      _MethodSelector(
                        methods: methods,
                        selected: _selectedMethod,
                        onChanged: (m) => setState(() => _selectedMethod = m),
                      ),
                    ],
                    const SizedBox(height: 24),
                    TextField(
                      controller: _codeController,
                      keyboardType: TextInputType.number,
                      maxLength: 6,
                      autofocus: true,
                      textInputAction: TextInputAction.done,
                      onSubmitted: (_) => _submit(),
                      decoration: InputDecoration(
                        labelText: '6-digit code',
                        border: const OutlineInputBorder(),
                        errorText: errorMessage,
                      ),
                    ),
                    const SizedBox(height: 24),
                    AppButton(label: 'Verify', onPressed: _submit),
                  ],
                ),
        ),
      ),
    );
  }
}

class _MethodSelector extends StatelessWidget {
  const _MethodSelector({
    required this.methods,
    required this.selected,
    required this.onChanged,
  });

  final List<String> methods;
  final String selected;
  final ValueChanged<String> onChanged;

  static const _labels = {'totp': 'Authenticator app', 'fido2': 'Security key'};

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      children: methods.map((m) {
        final isSelected = m == selected;
        return Semantics(
          selected: isSelected,
          child: FilterChip(
            label: Text(_labels[m] ?? m),
            selected: isSelected,
            onSelected: (_) => onChanged(m),
          ),
        );
      }).toList(),
    );
  }
}

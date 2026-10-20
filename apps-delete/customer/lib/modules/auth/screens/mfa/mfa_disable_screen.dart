import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../services/auth_api_service.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-036 — MFA disable screen.
/// Allows an authenticated user to revoke an enrolled MFA method.
class MfaDisableScreen extends ConsumerStatefulWidget {
  const MfaDisableScreen({super.key});

  @override
  ConsumerState<MfaDisableScreen> createState() => _MfaDisableScreenState();
}

class _MfaDisableScreenState extends ConsumerState<MfaDisableScreen> {
  String _selectedMethod = 'totp';
  bool _isLoading = false;
  bool _success = false;
  String? _error;

  AuthApiService get _api => ref.read(authApiServiceProvider);

  Future<void> _disable() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Disable Two-Factor?'),
        content: Text(
          'Disabling ${_selectedMethod.toUpperCase()} removes this security '
          'layer. You can re-enable it at any time.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text('Disable',
                  style: TextStyle(
                      color: Theme.of(context).colorScheme.error))),
        ],
      ),
    );
    if (confirmed != true) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      await _api.disableMfa(_selectedMethod);
      setState(() {
        _isLoading = false;
        _success = true;
      });
    } on AuthApiException catch (e) {
      setState(() {
        _isLoading = false;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Disable Two-Factor')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: _isLoading
              ? const Center(child: LoadingWidget(message: 'Disabling…'))
              : _success
                  ? _SuccessState(
                      onDone: () => Navigator.of(context).pop(true),
                    )
                  : _SelectionState(
                      selectedMethod: _selectedMethod,
                      error: _error,
                      onMethodChanged: (m) =>
                          setState(() => _selectedMethod = m),
                      onDisable: _disable,
                    ),
        ),
      ),
    );
  }
}

class _SelectionState extends StatelessWidget {
  const _SelectionState({
    required this.selectedMethod,
    required this.error,
    required this.onMethodChanged,
    required this.onDisable,
  });

  final String selectedMethod;
  final String? error;
  final ValueChanged<String> onMethodChanged;
  final VoidCallback onDisable;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Select the method to disable:',
            style: Theme.of(context).textTheme.bodyMedium),
        const SizedBox(height: 16),
        for (final method in ['totp', 'fido2'])
          RadioListTile<String>(
            title: Text(method == 'totp' ? 'Authenticator app' : 'Security key'),
            value: method,
            groupValue: selectedMethod,
            onChanged: (v) => v != null ? onMethodChanged(v) : null,
          ),
        if (error != null) ...[
          const SizedBox(height: 12),
          Semantics(
            liveRegion: true,
            child: Text(
              error!,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: Theme.of(context).colorScheme.error),
            ),
          ),
        ],
        const SizedBox(height: 24),
        AppButton(
          label: 'Disable',
          variant: ButtonVariant.danger,
          onPressed: onDisable,
        ),
      ],
    );
  }
}

class _SuccessState extends StatelessWidget {
  const _SuccessState({required this.onDone});

  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          liveRegion: true,
          child: Icon(
            Icons.check_circle_outline,
            size: 64,
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
        const SizedBox(height: 24),
        Text(
          'Two-factor disabled',
          style: Theme.of(context).textTheme.headlineMedium,
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 32),
        AppButton(label: 'Done', onPressed: onDone),
      ],
    );
  }
}

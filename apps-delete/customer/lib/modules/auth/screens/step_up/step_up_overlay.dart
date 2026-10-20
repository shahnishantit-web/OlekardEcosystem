import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../models/auth_models.dart';
import '../../state/auth_state.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-037 — Step-up authentication overlay.
/// Shown as a bottom sheet that does NOT navigate away from the in-progress
/// operation. The calling widget's context is preserved beneath the sheet.
///
/// Returns the [StepUpTokenData] on success, or null on dismissal/failure.
Future<StepUpTokenData?> showStepUpOverlay(
  BuildContext context, {
  required String stepUpToken,
  required List<String> methods,
  required DateTime expiresAt,
  required WidgetRef ref,
}) {
  return showModalBottomSheet<StepUpTokenData>(
    context: context,
    isScrollControlled: true,
    isDismissible: false,
    enableDrag: false,
    useSafeArea: true,
    builder: (ctx) => _StepUpSheet(
      stepUpToken: stepUpToken,
      methods: methods,
      expiresAt: expiresAt,
      ref: ref,
    ),
  );
}

class _StepUpSheet extends ConsumerStatefulWidget {
  const _StepUpSheet({
    required this.stepUpToken,
    required this.methods,
    required this.expiresAt,
    required this.ref,
  });

  final String stepUpToken;
  final List<String> methods;
  final DateTime expiresAt;
  final WidgetRef ref;

  @override
  ConsumerState<_StepUpSheet> createState() => _StepUpSheetState();
}

class _StepUpSheetState extends ConsumerState<_StepUpSheet> {
  final _credentialController = TextEditingController();
  String _selectedMethod = 'totp';
  String? _error;

  @override
  void initState() {
    super.initState();
    _selectedMethod = widget.methods.isNotEmpty ? widget.methods.first : 'totp';
  }

  @override
  void dispose() {
    _credentialController.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    final credential = _credentialController.text.trim();
    if (credential.isEmpty) {
      setState(() => _error = 'Please enter the verification code.');
      return;
    }

    final result = await ref
        .read(authStateNotifierProvider.notifier)
        .verifyStepUp(
          stepUpToken: widget.stepUpToken,
          method: _selectedMethod,
          credential: credential,
        );

    if (!mounted) return;

    if (result != null) {
      Navigator.of(context).pop(result);
    } else {
      final authState = ref.read(authStateNotifierProvider).valueOrNull;
      setState(() {
        _error = switch (authState) {
          AuthStepUpPending() => 'Verification failed. Please try again.',
          _ => null,
        };
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final authAsync = ref.watch(authStateNotifierProvider);
    final isLoading = authAsync.isLoading;

    return Padding(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 24,
        bottom: MediaQuery.viewInsetsOf(context).bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(
                    'Additional verification required',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.close),
                tooltip: 'Cancel',
                onPressed: () => Navigator.of(context).pop(null),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'This operation requires additional verification.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
          ),
          const SizedBox(height: 24),
          if (isLoading)
            const Center(child: LoadingWidget(message: 'Verifying…'))
          else ...[
            if (widget.methods.length > 1) ...[
              Wrap(
                spacing: 8,
                children: widget.methods.map((m) {
                  final isSelected = m == _selectedMethod;
                  return Semantics(
                    selected: isSelected,
                    child: FilterChip(
                      label: Text(_methodLabel(m)),
                      selected: isSelected,
                      onSelected: (_) =>
                          setState(() => _selectedMethod = m),
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 16),
            ],
            TextField(
              controller: _credentialController,
              keyboardType: TextInputType.number,
              maxLength: 6,
              autofocus: true,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _verify(),
              decoration: InputDecoration(
                labelText: _credentialLabel(_selectedMethod),
                border: const OutlineInputBorder(),
                errorText: _error,
              ),
            ),
            const SizedBox(height: 16),
            AppButton(
              label: 'Verify',
              onPressed: isLoading ? null : _verify,
            ),
          ],
        ],
      ),
    );
  }

  static String _methodLabel(String method) {
    return switch (method) {
      'totp' => 'Authenticator app',
      'pin' => 'PIN',
      'biometric' => 'Biometric',
      _ => method,
    };
  }

  static String _credentialLabel(String method) {
    return switch (method) {
      'totp' => '6-digit authenticator code',
      'pin' => 'PIN',
      _ => 'Verification code',
    };
  }
}

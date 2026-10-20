import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-004 — Initiate email change.
/// States: default, loading, success (challenge sent), format/in-progress/rate-limit errors.
class EmailChangeScreen extends ConsumerStatefulWidget {
  const EmailChangeScreen({super.key});

  @override
  ConsumerState<EmailChangeScreen> createState() => _EmailChangeScreenState();
}

class _EmailChangeScreenState extends ConsumerState<EmailChangeScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();

  @override
  void dispose() {
    _emailCtrl.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    ref
        .read(profileStateNotifierProvider.notifier)
        .initiateEmailChange(_emailCtrl.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final isSubmitting = loaded?.isSubmitting ?? false;
    final errorMessage = loaded?.errorMessage;
    final errorCode = loaded?.errorCode;
    final challenge = loaded?.contactChallenge;

    // Challenge issued — navigate to OTP verify
    if (challenge != null && challenge.type == 'email' && !challenge.hasError) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          Navigator.of(context).pushReplacementNamed('/profile/email/verify');
        }
      });
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Change Email')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isSubmitting
              ? const Center(child: LoadingWidget(message: 'Sending code…'))
              : Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Semantics(
                        header: true,
                        child: Text(
                          'Enter your new email',
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'We will send a verification code to confirm the change.',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                            ),
                      ),
                      const SizedBox(height: 24),
                      Semantics(
                        label: 'New email address',
                        child: TextFormField(
                          controller: _emailCtrl,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.done,
                          autofocus: true,
                          decoration: const InputDecoration(
                            labelText: 'New email address',
                            border: OutlineInputBorder(),
                            prefixIcon: Icon(Icons.email_outlined),
                          ),
                          validator: (v) {
                            if (v == null || v.trim().isEmpty) {
                              return 'Email address is required';
                            }
                            if (!RegExp(r'^[^@]+@[^@]+\.[^@]+$')
                                .hasMatch(v.trim())) {
                              return 'Enter a valid email address';
                            }
                            return null;
                          },
                          onFieldSubmitted: (_) => _submit(),
                        ),
                      ),
                      if (errorMessage != null) ...[
                        const SizedBox(height: 16),
                        Semantics(
                          liveRegion: true,
                          child: _ErrorBanner(
                            message: _friendlyError(errorCode, errorMessage),
                          ),
                        ),
                      ],
                      const SizedBox(height: 24),
                      AppButton(
                        label: 'Send verification code',
                        onPressed: isSubmitting ? null : _submit,
                      ),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  String _friendlyError(String? code, String message) => switch (code) {
        'CONTACT_CHANGE_IN_PROGRESS' =>
          'A contact change is already in progress. Please complete or wait for it to expire.',
        'RATE_LIMIT_EXCEEDED' =>
          'Too many attempts. Please try again later.',
        _ => message,
      };
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.onErrorContainer,
              ),
        ),
      );
}

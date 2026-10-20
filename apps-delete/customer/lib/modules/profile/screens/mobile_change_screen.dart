import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-005 — Initiate mobile number change.
/// Mirrors the email change screen with E.164 phone validation.
class MobileChangeScreen extends ConsumerStatefulWidget {
  const MobileChangeScreen({super.key});

  @override
  ConsumerState<MobileChangeScreen> createState() => _MobileChangeScreenState();
}

class _MobileChangeScreenState extends ConsumerState<MobileChangeScreen> {
  final _formKey = GlobalKey<FormState>();
  final _mobileCtrl = TextEditingController();

  @override
  void dispose() {
    _mobileCtrl.dispose();
    super.dispose();
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    ref
        .read(profileStateNotifierProvider.notifier)
        .initiateMobileChange(_mobileCtrl.text.trim());
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
    if (challenge != null && challenge.type == 'mobile' && !challenge.hasError) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          Navigator.of(context).pushReplacementNamed('/profile/mobile/verify');
        }
      });
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Change Mobile Number')),
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
                          'Enter your new mobile number',
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'We will send a verification code via SMS.',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                            ),
                      ),
                      const SizedBox(height: 24),
                      Semantics(
                        label: 'New mobile number in E.164 format',
                        child: TextFormField(
                          controller: _mobileCtrl,
                          keyboardType: TextInputType.phone,
                          textInputAction: TextInputAction.done,
                          autofocus: true,
                          decoration: const InputDecoration(
                            labelText: 'New mobile number',
                            hintText: '+267 7X XXX XXX',
                            border: OutlineInputBorder(),
                            prefixIcon: Icon(Icons.phone),
                          ),
                          validator: (v) {
                            if (v == null || v.trim().isEmpty) {
                              return 'Mobile number is required';
                            }
                            if (!RegExp(r'^\+[0-9]{9,15}$')
                                .hasMatch(v.trim())) {
                              return 'Enter number in E.164 format, e.g. +26771234567';
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
        'RATE_LIMIT_EXCEEDED' => 'Too many attempts. Please try again later.',
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

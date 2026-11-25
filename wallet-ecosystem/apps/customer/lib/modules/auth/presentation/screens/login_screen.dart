import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';
import '../../../../shared/widgets/primary_button.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phoneController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _showPhoneStep = true;

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authProvider);
    final pinStep = !_showPhoneStep &&
        (auth.status == AuthStateEnum.otpPending ||
            auth.status == AuthStateEnum.verifyingPin);
    final loading = auth.status == AuthStateEnum.authenticating;
    ref.listen(authProvider, (previous, next) {
      if (next.isAuthenticated) context.go('/home');
      if (next.status == AuthStateEnum.otpPending) {
        setState(() => _showPhoneStep = false);
      }
    });

    return Scaffold(
      resizeToAvoidBottomInset: true,
      body: SafeArea(
        child: LayoutBuilder(builder: (context, constraints) {
          return Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: SingleChildScrollView(
                keyboardDismissBehavior:
                    ScrollViewKeyboardDismissBehavior.onDrag,
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 20),
                child: ConstrainedBox(
                  constraints:
                      BoxConstraints(minHeight: constraints.maxHeight - 44),
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 180),
                    child: pinStep
                        ? _pinContent(context, auth, key: const ValueKey('pin'))
                        : _phoneContent(context, auth, loading,
                            key: const ValueKey('phone')),
                  ),
                ),
              ),
            ),
          );
        }),
      ),
    );
  }

  Widget _brand({required bool compact}) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Image.asset('assets/branding/olekard-logo.png',
              semanticLabel: 'OleKard',
              width: 168,
              height: 48,
              fit: BoxFit.contain),
          SizedBox(height: compact ? 24 : 38),
        ],
      );

  Widget _phoneBrand() => Padding(
        padding: const EdgeInsets.only(bottom: 34),
        child: Center(
          child: Semantics(
            label: 'OleKard',
            child: Text.rich(
              const TextSpan(children: [
                TextSpan(
                    text: 'Ole',
                    style: TextStyle(color: AppColors.textPrimary)),
                TextSpan(
                    text: 'Kard', style: TextStyle(color: Color(0xFF0E9F6E))),
              ]),
              style: const TextStyle(
                  fontSize: 32,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -1.1),
            ),
          ),
        ),
      );

  Widget _phoneContent(BuildContext context, AuthState auth, bool loading,
          {Key? key}) =>
      Form(
        key: _formKey,
        child: Column(
          key: key,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 20),
            _phoneBrand(),
            Text('Welcome back',
                style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 8),
            Text('Sign in to OleKard Ecosystem',
                style: Theme.of(context)
                    .textTheme
                    .bodyLarge
                    ?.copyWith(color: AppColors.textSecondary)),
            const SizedBox(height: 32),
            TextFormField(
              controller: _phoneController,
              keyboardType: kIsWeb ? TextInputType.text : TextInputType.phone,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.telephoneNumber],
              onFieldSubmitted: (_) => _submit(),
              decoration: const InputDecoration(
                labelText: 'Phone number',
                hintText: '+267 71 000 000',
                prefixIcon: Icon(Icons.phone_iphone_rounded),
              ),
              validator: (value) {
                final phone = value?.replaceAll(RegExp(r'[\s()-]'), '') ?? '';
                if (phone.isEmpty) return 'Enter your phone number';
                if (!RegExp(r'^\+\d{10,15}$').hasMatch(phone))
                  return 'Use international format, for example +267…';
                return null;
              },
            ),
            if (auth.error != null) _inlineError(auth.error!),
            const SizedBox(height: 18),
            PrimaryButton(
                label: 'Log In', isLoading: loading, onPressed: _submit),
            const SizedBox(height: 18),
            Center(
                child: TextButton(
                    onPressed: () => context.push('/register'),
                    child: const Text('New to OleKard? Create account'))),
            Center(
                child: TextButton(
                    onPressed: () => context.push('/forgot-pin'),
                    child: const Text('Forgot PIN?'))),
          ],
        ),
      );

  Widget _pinContent(BuildContext context, AuthState auth, {Key? key}) {
    final verifying = auth.status == AuthStateEnum.verifyingPin;
    return Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: IconButton(
            tooltip: 'Change phone number',
            onPressed:
                verifying ? null : () => setState(() => _showPhoneStep = true),
            icon: const Icon(Icons.arrow_back_rounded),
          ),
        ),
        _brand(compact: true),
        const Icon(Icons.lock_outline_rounded,
            size: 30, color: AppColors.primary),
        const SizedBox(height: 14),
        Text('Enter your PIN',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: 8),
        Text('Enter your 6-digit PIN to securely access your wallet.',
            textAlign: TextAlign.center,
            style: Theme.of(context)
                .textTheme
                .bodyMedium
                ?.copyWith(color: AppColors.textSecondary)),
        const SizedBox(height: 8),
        Text(_maskedPhone(_phoneController.text.trim()),
            textAlign: TextAlign.center,
            style: Theme.of(context)
                .textTheme
                .labelLarge
                ?.copyWith(color: AppColors.textSecondary)),
        const SizedBox(height: 16),
        PinPadWidget(
          enabled: !verifying,
          onCompleted: (pin) =>
              unawaited(ref.read(authProvider.notifier).verifyLogin(pin)),
        ),
        if (auth.error != null) _inlineError(auth.error!),
        if (verifying) ...[
          const SizedBox(height: 12),
          const LinearProgressIndicator(),
          const SizedBox(height: 8),
          const Text('Verifying securely…', textAlign: TextAlign.center),
        ],
        const SizedBox(height: 14),
        Center(
            child: TextButton(
                onPressed: verifying ? null : () => context.push('/forgot-pin'),
                child: const Text('Forgot PIN?'))),
      ],
    );
  }

  Widget _inlineError(String message) => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
              color: AppColors.errorBg,
              borderRadius: BorderRadius.circular(12)),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Icon(Icons.error_outline_rounded,
                size: 20, color: AppColors.error),
            const SizedBox(width: 8),
            Expanded(
                child: Text(message,
                    style: const TextStyle(color: AppColors.error))),
          ]),
        ),
      );

  void _submit() {
    if (_formKey.currentState!.validate()) {
      FocusScope.of(context).unfocus();
      setState(() => _showPhoneStep = true);
      ref.read(authProvider.notifier).login(
          _phoneController.text.trim().replaceAll(RegExp(r'[\s()-]'), ''));
    }
  }

  String _maskedPhone(String phone) {
    if (phone.length < 7) return phone;
    return '${phone.substring(0, phone.length - 4).replaceAll(RegExp(r'\d(?=.{4})'), '•')} ${phone.substring(phone.length - 4)}';
  }
}

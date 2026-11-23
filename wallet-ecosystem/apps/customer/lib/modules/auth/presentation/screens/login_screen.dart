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
  BuildContext? _pinEntrySheetContext;
  bool _pinEntrySheetOpen = false;

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authProvider);
    final isLoading = auth.status == AuthStateEnum.authenticating;

    ref.listen(authProvider, (previous, next) {
      if (next.isAuthenticated) {
        final sheetContext = _pinEntrySheetContext;
        if (_pinEntrySheetOpen &&
            sheetContext != null &&
            sheetContext.mounted) {
          Navigator.of(sheetContext).pop();
        }
        context.go('/home');
      }
      if (previous?.status == AuthStateEnum.authenticating &&
          next.status == AuthStateEnum.otpPending) {
        _showPinEntry(context);
      }
      if (next.error != null) {
        ScaffoldMessenger.of(context)
          ..hideCurrentSnackBar()
          ..showSnackBar(SnackBar(content: Text(next.error!)));
      }
    });

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: SingleChildScrollView(
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: AppSpacing.md),
                  Center(
                    child: Image.asset(
                      'assets/branding/olekard-logo.png',
                      semanticLabel: 'OleKard',
                      width: 190,
                      height: 52,
                      fit: BoxFit.contain,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.xxl),
                  const Text('Welcome back', style: AppTypography.headline),
                  const SizedBox(height: AppSpacing.sm),
                  const Text('Log in to your wallet',
                      style: AppTypography.body),
                  const SizedBox(height: AppSpacing.xl),
                  TextFormField(
                    controller: _phoneController,
                    keyboardType:
                        kIsWeb ? TextInputType.text : TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Phone Number',
                      hintText: '+254712345678',
                      prefixIcon: Icon(Icons.phone),
                    ),
                    validator: (v) {
                      if (v == null || v.trim().isEmpty) {
                        return 'Phone number required';
                      }
                      if (!RegExp(r'^\+\d{10,15}$').hasMatch(v.trim())) {
                        return 'Enter a valid phone number in E.164 format';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  PrimaryButton(
                    label: 'Log In',
                    isLoading: isLoading,
                    onPressed: _submit,
                  ),
                  const SizedBox(height: AppSpacing.md),
                  TextButton(
                    onPressed: () => context.push('/register'),
                    child: const Text('New here? Create account'),
                  ),
                  TextButton(
                    onPressed: () => context.push('/forgot-pin'),
                    child: const Text('Forgot PIN?'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  void _submit() {
    if (_formKey.currentState!.validate()) {
      FocusScope.of(context).unfocus();
      ref.read(authProvider.notifier).login(_phoneController.text.trim());
    }
  }

  void _showPinEntry(BuildContext context) {
    _pinEntrySheetOpen = true;
    unawaited(showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        _pinEntrySheetContext = ctx;
        return Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
          ),
          child: Align(
            alignment: Alignment.bottomCenter,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Container(
                margin: const EdgeInsets.all(AppSpacing.md),
                padding: const EdgeInsets.fromLTRB(
                  AppSpacing.xl,
                  AppSpacing.md,
                  AppSpacing.xl,
                  AppSpacing.xl,
                ),
                decoration: BoxDecoration(
                  color: Theme.of(ctx).colorScheme.surface,
                  borderRadius: BorderRadius.circular(28),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.18),
                      blurRadius: 28,
                      offset: const Offset(0, 8),
                    ),
                  ],
                ),
                child: Consumer(builder: (context, ref, _) {
                  final authState = ref.watch(authProvider);
                  final isVerifying =
                      authState.status == AuthStateEnum.verifyingPin;
                  final theme = Theme.of(context);

                  return Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 40,
                        height: 4,
                        decoration: BoxDecoration(
                          color: theme.colorScheme.outlineVariant,
                          borderRadius: BorderRadius.circular(4),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.xl),
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: theme.colorScheme.primaryContainer,
                        child: Icon(
                          Icons.lock_outline_rounded,
                          size: 28,
                          color: theme.colorScheme.onPrimaryContainer,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        'Enter your PIN',
                        style: theme.textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'Enter your 6-digit PIN to securely access your wallet.',
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        _maskedPhone(_phoneController.text.trim()),
                        style: theme.textTheme.labelLarge?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.xl),
                      PinPadWidget(
                        enabled: !isVerifying,
                        onCompleted: (pin) {
                          if (!isVerifying) {
                            unawaited(ref
                                .read(authProvider.notifier)
                                .verifyLogin(pin));
                          }
                        },
                      ),
                      if (authState.error != null && !isVerifying) ...[
                        const SizedBox(height: AppSpacing.sm),
                        Text(
                          authState.error!,
                          textAlign: TextAlign.center,
                          style: TextStyle(color: theme.colorScheme.error),
                        ),
                      ],
                      if (isVerifying) ...[
                        const SizedBox(height: AppSpacing.md),
                        const LinearProgressIndicator(),
                        const SizedBox(height: AppSpacing.xs),
                        Text(
                          'Verifying securely…',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                      const SizedBox(height: AppSpacing.md),
                      TextButton(
                        onPressed: () {
                          Navigator.of(ctx).pop();
                          context.push('/forgot-pin');
                        },
                        child: const Text('Forgot PIN?'),
                      ),
                    ],
                  );
                }),
              ),
            ),
          ),
        );
      },
    ).whenComplete(() {
      _pinEntrySheetOpen = false;
      _pinEntrySheetContext = null;
    }));
  }

  String _maskedPhone(String phone) {
    if (phone.length < 7) return phone;
    return '${phone.substring(0, phone.length - 4).replaceAll(RegExp(r'\d(?=.{4})'), '•')} ${phone.substring(phone.length - 4)}';
  }
}

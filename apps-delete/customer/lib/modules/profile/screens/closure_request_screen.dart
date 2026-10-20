import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/card.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-009 — Account closure request / cancel closure.
/// States: default (with/without obligations), confirm dialog, submitting,
/// pending_closure (cancel CTA), generic error.
class ClosureRequestScreen extends ConsumerWidget {
  const ClosureRequestScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final isSubmitting = loaded?.isSubmitting ?? profileAsync.isLoading;
    final status = loaded?.profile.accountStatus ?? '';
    final obligations = loaded?.accountStatus?.closureObligations;
    final errorMessage = loaded?.errorMessage;
    final successMessage = loaded?.successMessage;

    final isPendingClosure = status == 'pending_closure';

    return Scaffold(
      appBar: AppBar(title: const Text('Close Account')),
      body: SafeArea(
        child: isSubmitting
            ? const Center(child: LoadingWidget(message: 'Processing…'))
            : Padding(
                padding: const EdgeInsets.all(24),
                child: isPendingClosure
                    ? _PendingClosureBody(
                        ref: ref,
                        errorMessage: errorMessage,
                        successMessage: successMessage,
                      )
                    : _RequestClosureBody(
                        ref: ref,
                        context: context,
                        obligations: obligations,
                        errorMessage: errorMessage,
                        successMessage: successMessage,
                      ),
              ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Pending closure — account already in closure queue; offer cancellation.
// ---------------------------------------------------------------------------

class _PendingClosureBody extends StatelessWidget {
  const _PendingClosureBody({
    required this.ref,
    this.errorMessage,
    this.successMessage,
  });

  final WidgetRef ref;
  final String? errorMessage;
  final String? successMessage;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Closure requested',
            style: theme.textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 12),
        Text(
          'Your account is scheduled for closure. '
          'You can cancel this request if you change your mind.',
          style: theme.textTheme.bodyMedium,
        ),
        if (successMessage != null) ...[
          const SizedBox(height: 16),
          Semantics(
            liveRegion: true,
            child: _Banner(
              message: successMessage!,
              color: theme.colorScheme.tertiaryContainer,
              textColor: theme.colorScheme.onTertiaryContainer,
            ),
          ),
        ],
        if (errorMessage != null) ...[
          const SizedBox(height: 16),
          Semantics(
            liveRegion: true,
            child: _Banner(
              message: errorMessage!,
              color: theme.colorScheme.errorContainer,
              textColor: theme.colorScheme.onErrorContainer,
            ),
          ),
        ],
        const Spacer(),
        AppButton(
          label: 'Cancel closure request',
          onPressed: () =>
              ref.read(profileStateNotifierProvider.notifier).cancelClosure(),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Default — ask to close; show obligations when blocked.
// ---------------------------------------------------------------------------

class _RequestClosureBody extends StatelessWidget {
  const _RequestClosureBody({
    required this.ref,
    required this.context,
    this.obligations,
    this.errorMessage,
    this.successMessage,
  });

  final WidgetRef ref;
  final BuildContext context;
  final List<String>? obligations;
  final String? errorMessage;
  final String? successMessage;

  bool get _isBlocked =>
      obligations != null && obligations!.isNotEmpty;

  @override
  Widget build(BuildContext ctx) {
    final theme = Theme.of(ctx);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Close your account',
            style: theme.textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 12),
        Text(
          'Closing your account is permanent. '
          'All personal data will be deleted per our data-retention policy.',
          style: theme.textTheme.bodyMedium,
        ),
        if (_isBlocked) ...[
          const SizedBox(height: 24),
          Semantics(
            liveRegion: true,
            child: Text(
              'You must resolve these before closing your account:',
              style: theme.textTheme.titleSmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
          const SizedBox(height: 8),
          ...obligations!.map(
            (o) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: AppCard(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Icon(Icons.warning_amber_outlined,
                          size: 18, color: theme.colorScheme.error),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          o.replaceAll('_', ' '),
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
        if (successMessage != null) ...[
          const SizedBox(height: 16),
          Semantics(
            liveRegion: true,
            child: _Banner(
              message: successMessage!,
              color: theme.colorScheme.tertiaryContainer,
              textColor: theme.colorScheme.onTertiaryContainer,
            ),
          ),
        ],
        if (errorMessage != null) ...[
          const SizedBox(height: 16),
          Semantics(
            liveRegion: true,
            child: _Banner(
              message: errorMessage!,
              color: theme.colorScheme.errorContainer,
              textColor: theme.colorScheme.onErrorContainer,
            ),
          ),
        ],
        const Spacer(),
        AppButton(
          label: 'Request account closure',
          variant: ButtonVariant.danger,
          onPressed: _isBlocked ? null : () => _confirmAndSubmit(ctx),
        ),
      ],
    );
  }

  void _confirmAndSubmit(BuildContext ctx) {
    showDialog<bool>(
      context: ctx,
      builder: (dialogCtx) => AlertDialog(
        title: const Text('Are you sure?'),
        content: const Text(
          'This will permanently close your account. '
          'This action cannot be undone.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogCtx).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(dialogCtx).pop(true),
            child: Text(
              'Close my account',
              style: TextStyle(
                color: Theme.of(dialogCtx).colorScheme.error,
              ),
            ),
          ),
        ],
      ),
    ).then((confirmed) {
      if (confirmed == true) {
        ref.read(profileStateNotifierProvider.notifier).requestClosure();
      }
    });
  }
}

// ---------------------------------------------------------------------------
// Shared inline banner.
// ---------------------------------------------------------------------------

class _Banner extends StatelessWidget {
  const _Banner({
    required this.message,
    required this.color,
    required this.textColor,
  });

  final String message;
  final Color color;
  final Color textColor;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: textColor),
        ),
      );
}

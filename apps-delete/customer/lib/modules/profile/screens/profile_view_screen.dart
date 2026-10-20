import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/badge.dart';
import '../../../shared/widgets/loading.dart';
import '../../../shared/widgets/error_state.dart';
import '../models/profile_models.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-001 — Customer profile view.
/// States: loading skeleton, loaded (with photo), error+retry.
class ProfileViewScreen extends ConsumerWidget {
  const ProfileViewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(profileStateNotifierProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Profile'),
        actions: [
          Semantics(
            button: true,
            label: 'Edit profile',
            child: IconButton(
              icon: const Icon(Icons.edit),
              onPressed: () => Navigator.of(context).pushNamed('/profile/edit'),
              tooltip: 'Edit personal info',
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: switch (profileAsync) {
          AsyncLoading() => const Center(child: _ProfileLoadingSkeleton()),
          AsyncData(:final value) => switch (value) {
              ProfileLoading() =>
                const Center(child: _ProfileLoadingSkeleton()),
              ProfileLoaded(:final profile) =>
                _ProfileBody(profile: profile),
              ProfileError(:final message) => Center(
                  child: ErrorStateWidget(
                    message: message,
                    onRetry: () => ref
                        .read(profileStateNotifierProvider.notifier)
                        .reload(),
                  ),
                ),
              ProfileNotFound() => Center(
                  child: ErrorStateWidget(
                    message: 'Profile not found. Please try again.',
                    onRetry: () => ref
                        .read(profileStateNotifierProvider.notifier)
                        .reload(),
                  ),
                ),
              _ => const SizedBox.shrink(),
            },
          AsyncError(:final error) => Center(
              child: ErrorStateWidget(
                message: error.toString(),
                onRetry: () =>
                    ref.read(profileStateNotifierProvider.notifier).reload(),
              ),
            ),
          _ => const SizedBox.shrink(),
        },
      ),
    );
  }
}

class _ProfileBody extends StatelessWidget {
  const _ProfileBody({required this.profile});

  final ProfileData profile;

  BadgeVariant _statusVariant(String status) => switch (status) {
        'active' => BadgeVariant.success,
        'suspended' => BadgeVariant.error,
        'pending_closure' => BadgeVariant.warning,
        'closed' => BadgeVariant.neutral,
        _ => BadgeVariant.neutral,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        // Photo
        Center(
          child: Semantics(
            label: 'Profile photo for ${profile.displayName}',
            child: CircleAvatar(
              radius: 48,
              backgroundImage: profile.profilePhotoUrl != null
                  ? NetworkImage(profile.profilePhotoUrl!)
                  : null,
              child: profile.profilePhotoUrl == null
                  ? const Icon(Icons.person, size: 48)
                  : null,
            ),
          ),
        ),
        const SizedBox(height: 8),
        // Name
        Center(
          child: Semantics(
            header: true,
            child: Text(
              profile.displayName,
              style: theme.textTheme.headlineSmall,
              textAlign: TextAlign.center,
            ),
          ),
        ),
        const SizedBox(height: 4),
        // Account status badge
        Center(
          child: AppBadge(
            label: profile.accountStatus.replaceAll('_', ' ').toUpperCase(),
            variant: _statusVariant(profile.accountStatus),
          ),
        ),
        const SizedBox(height: 24),
        // Fields
        _FieldRow(label: 'Email', value: profile.email),
        _FieldRow(label: 'Mobile', value: profile.mobileNumber),
        _FieldRow(label: 'Date of birth', value: profile.dateOfBirth),
        _FieldRow(label: 'Gender', value: profile.gender),
        _FieldRow(label: 'Preferred language', value: profile.preferredLanguage),
        if (profile.address != null) ...[
          const Divider(height: 32),
          Semantics(
            header: true,
            child: Text('Address', style: theme.textTheme.titleMedium),
          ),
          const SizedBox(height: 8),
          _FieldRow(label: 'Street', value: profile.address!.street),
          _FieldRow(label: 'City', value: profile.address!.city),
          _FieldRow(label: 'Region', value: profile.address!.region),
          _FieldRow(label: 'Postal code', value: profile.address!.postalCode),
          _FieldRow(label: 'Country', value: profile.address!.country),
        ],
      ],
    );
  }
}

class _FieldRow extends StatelessWidget {
  const _FieldRow({required this.label, required this.value});

  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    if (value == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Semantics(
        label: '$label: $value',
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 140,
              child: Text(
                label,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
            ),
            Expanded(child: Text(value!, style: Theme.of(context).textTheme.bodyMedium)),
          ],
        ),
      ),
    );
  }
}

/// Loading skeleton — shown while the initial profile fetch is in progress.
class _ProfileLoadingSkeleton extends StatelessWidget {
  const _ProfileLoadingSkeleton();

  @override
  Widget build(BuildContext context) {
    return const LoadingWidget(message: 'Loading profile…');
  }
}

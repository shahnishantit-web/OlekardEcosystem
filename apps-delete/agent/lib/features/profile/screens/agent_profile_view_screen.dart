import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../shared/widgets/badge.dart';
import '../../../shared/widgets/loading.dart';
import '../../../shared/widgets/error_state.dart';
import '../models/agent_profile_models.dart';
import '../state/agent_profile_state.dart';
import '../providers/agent_profile_state_notifier.dart';

/// US-USER-001 (Agent) — agent profile view.
/// States: loading skeleton, loaded (with photo), error+retry.
class AgentProfileViewScreen extends ConsumerWidget {
  const AgentProfileViewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(agentProfileStateNotifierProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Profile'),
        actions: [
          Semantics(
            button: true,
            label: 'Edit profile',
            child: IconButton(
              icon: const Icon(Icons.edit),
              onPressed: () => context.push('/profile/edit'),
              tooltip: 'Edit personal info',
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: switch (profileAsync) {
          AsyncLoading() => const Center(child: _ProfileLoadingSkeleton()),
          AsyncData(:final value) => switch (value) {
              AgentProfileLoading() =>
                const Center(child: _ProfileLoadingSkeleton()),
              AgentProfileLoaded(:final profile) =>
                _ProfileBody(profile: profile),
              AgentProfileError(:final message) => Center(
                  child: ErrorStateWidget(
                    message: message,
                    onRetry: () => ref
                        .read(agentProfileStateNotifierProvider.notifier)
                        .reload(),
                  ),
                ),
              AgentProfileNotFound() => Center(
                  child: ErrorStateWidget(
                    message: 'Profile not found. Please try again.',
                    onRetry: () => ref
                        .read(agentProfileStateNotifierProvider.notifier)
                        .reload(),
                  ),
                ),
              _ => const SizedBox.shrink(),
            },
          AsyncError(:final error) => Center(
              child: ErrorStateWidget(
                message: error.toString(),
                onRetry: () => ref
                    .read(agentProfileStateNotifierProvider.notifier)
                    .reload(),
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

  final AgentProfileData profile;

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
        Center(
          child: AppBadge(
            label: profile.accountStatus.replaceAll('_', ' ').toUpperCase(),
            variant: _statusVariant(profile.accountStatus),
          ),
        ),
        const SizedBox(height: 24),
        _FieldRow(label: 'Mobile', value: profile.mobileNumber),
        _FieldRow(label: 'Email', value: profile.email),
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
        const SizedBox(height: 32),
        // Quick-navigation links for other profile screens
        _NavTile(
          icon: Icons.photo_camera,
          label: 'Change photo',
          onTap: () => context.push('/profile/photo'),
        ),
        _NavTile(
          icon: Icons.phone,
          label: 'Change mobile number',
          onTap: () => context.push('/profile/mobile'),
        ),
        _NavTile(
          icon: Icons.account_circle,
          label: 'Account status',
          onTap: () => context.push('/profile/status'),
        ),
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
            Expanded(
                child:
                    Text(value!, style: Theme.of(context).textTheme.bodyMedium)),
          ],
        ),
      ),
    );
  }
}

class _NavTile extends StatelessWidget {
  const _NavTile(
      {required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: Icon(icon),
      title: Text(label),
      trailing: const Icon(Icons.chevron_right),
      onTap: onTap,
    );
  }
}

class _ProfileLoadingSkeleton extends StatelessWidget {
  const _ProfileLoadingSkeleton();

  @override
  Widget build(BuildContext context) =>
      const LoadingWidget(message: 'Loading profile…');
}

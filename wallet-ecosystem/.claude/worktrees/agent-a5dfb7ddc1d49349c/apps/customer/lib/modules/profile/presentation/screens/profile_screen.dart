import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../profile_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../modules/auth/presentation/auth_providers.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(profileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: profileAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 5),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (profile) => SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: CircleAvatar(
                  radius: 48,
                  backgroundImage: profile.avatarUrl != null
                      ? NetworkImage(profile.avatarUrl!)
                      : null,
                  child: profile.avatarUrl == null
                      ? Text(
                          profile.fullName.substring(0, 1).toUpperCase(),
                          style: const TextStyle(fontSize: 32),
                        )
                      : null,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Center(
                child: Text(profile.fullName, style: AppTypography.headline),
              ),
              Center(
                child: Text(profile.email, style: AppTypography.caption),
              ),
              const SizedBox(height: AppSpacing.lg),
              _InfoRow('Phone', profile.phoneNumber ?? '—'),
              _InfoRow('KYC Status', profile.kycStatus.toUpperCase()),
              _InfoRow(
                'Member since',
                profile.createdAt.toLocal().toString().substring(0, 10),
              ),
              const SizedBox(height: AppSpacing.xl),
              OutlinedButton(
                onPressed: () => context.push('/profile/edit'),
                child: const Text('Edit Profile'),
              ),
              const SizedBox(height: AppSpacing.md),
              OutlinedButton(
                onPressed: () => context.push('/security'),
                child: const Text('Security Settings'),
              ),
              const SizedBox(height: AppSpacing.md),
              TextButton(
                onPressed: () => ref.read(authProvider.notifier).logout(),
                child: const Text('Log Out', style: TextStyle(color: AppColors.error)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final String label;
  final String value;
  const _InfoRow(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(label, style: AppTypography.label),
          Text(value, style: AppTypography.body),
        ]),
      );
}

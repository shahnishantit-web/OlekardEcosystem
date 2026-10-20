import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';

class SecuritySettingsScreen extends StatelessWidget {
  const SecuritySettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Security Settings')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: ListView(
              children: [
                const _SectionHeader('Authentication'),
                ListTile(
                  leading: const Icon(Icons.lock_outline),
                  title: const Text('Change PIN'),
                  subtitle: const Text('Update your 6-digit transaction PIN'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => context.push('/change-pin'),
                ),
                const Divider(indent: AppSpacing.md, endIndent: AppSpacing.md),
                const _SectionHeader('Notifications'),
                ListTile(
                  leading: const Icon(Icons.notifications_outlined),
                  title: const Text('Notification Preferences'),
                  subtitle:
                      const Text('Manage push notification settings'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    // Stub: notification preferences managed via device Settings
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text(
                          'Manage notification permissions in your device Settings.',
                        ),
                      ),
                    );
                  },
                ),
                const Divider(indent: AppSpacing.md, endIndent: AppSpacing.md),
                const _SectionHeader('Devices'),
                ListTile(
                  leading: const Icon(Icons.devices_outlined),
                  title: const Text('Trusted Devices'),
                  subtitle: const Text('View and manage devices linked to your account'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    // Stub: trusted device management
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text(
                          'Trusted device management coming soon.',
                        ),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String title;
  const _SectionHeader(this.title);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
          AppSpacing.md, AppSpacing.md, AppSpacing.md, AppSpacing.xs),
      child: Text(
        title,
        style: AppTypography.labelLarge
            .copyWith(color: AppColors.onSurfaceVariant),
      ),
    );
  }
}

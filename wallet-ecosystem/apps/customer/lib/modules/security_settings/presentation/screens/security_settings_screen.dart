import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/theme/design_tokens.dart';

class SecuritySettingsScreen extends StatelessWidget {
  const SecuritySettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Security Settings')),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.lock_outline),
            title: const Text('Change PIN'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.push('/security/change-pin'),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.devices_outlined),
            title: const Text('Trusted Devices'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.push('/security/devices'),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Text(
              'Keep your account secure by using a strong PIN and managing devices that have access to your account.',
              style: AppTypography.caption.copyWith(color: Colors.grey),
            ),
          ),
        ],
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/domain_deep_link.dart';

/// Renders a tappable tile when the deep link is available, or a greyed
/// disabled tile when it's unavailable or an in-app route is unregistered.
class DomainDeepLinkTile extends StatelessWidget {
  final DomainDeepLink deepLink;

  const DomainDeepLinkTile({super.key, required this.deepLink});

  @override
  Widget build(BuildContext context) {
    return switch (deepLink) {
      AvailableLink(url: final url, label: final label) =>
        _buildAvailable(context, url, label),
      UnavailableLink(reason: final reason) => _buildUnavailable(reason),
    };
  }

  Widget _buildAvailable(BuildContext context, String url, String? label) {
    return Semantics(
      button: true,
      label: label ?? 'Open related item',
      child: ListTile(
        leading: const Icon(Icons.open_in_new),
        title: Text(label ?? 'View details'),
        onTap: () => _handleTap(context, url),
      ),
    );
  }

  Widget _buildUnavailable(String reason) {
    return Semantics(
      button: false,
      label: 'Domain link unavailable: $reason',
      child: ListTile(
        leading: const Icon(Icons.link_off),
        title: const Text('Domain link unavailable'),
        enabled: false,
      ),
    );
  }

  void _handleTap(BuildContext context, String url) {
    if (url.startsWith('https://') || url.startsWith('http://')) {
      launchUrl(Uri.parse(url));
      return;
    }
    try {
      context.go(url);
    } catch (_) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Domain unavailable')),
      );
    }
  }
}

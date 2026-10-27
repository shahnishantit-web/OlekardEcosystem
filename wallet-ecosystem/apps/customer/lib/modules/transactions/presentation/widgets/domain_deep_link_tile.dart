import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../domain/entities/domain_deep_link.dart';
import '../../../../../core/theme/design_tokens.dart';

class DomainDeepLinkTile extends StatelessWidget {
  final DomainDeepLink deepLink;

  const DomainDeepLinkTile({super.key, required this.deepLink});

  @override
  Widget build(BuildContext context) {
    return switch (deepLink) {
      AvailableLink(:final url, :final label) => Semantics(
          button: true,
          label: label ?? 'View related item',
          child: ListTile(
            leading: const Icon(Icons.open_in_new),
            title: Text(label ?? 'View related item'),
            onTap: () => _navigate(context, url),
          ),
        ),
      UnavailableLink() => Semantics(
          button: false,
          label: 'Domain link unavailable',
          child: ListTile(
            leading: Icon(Icons.link_off, color: Colors.grey.shade400),
            title: Text(
              'Unavailable',
              style: AppTypography.label.copyWith(color: Colors.grey),
            ),
            enabled: false,
          ),
        ),
    };
  }

  void _navigate(BuildContext context, String url) {
    if (url.startsWith('http://') || url.startsWith('https://')) {
      launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      return;
    }
    try {
      context.go(url);
    } on Exception {
      // Route not registered in this app — show inline message.
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Domain unavailable')),
      );
    }
  }
}

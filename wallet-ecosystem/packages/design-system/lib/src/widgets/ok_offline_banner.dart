import 'package:flutter/material.dart';
import '../tokens.dart';

class OkOfflineBanner extends StatelessWidget {
  final bool isOffline;

  const OkOfflineBanner({super.key, required this.isOffline});

  @override
  Widget build(BuildContext context) {
    if (!isOffline) return const SizedBox.shrink();

    return Semantics(
      label: 'Offline mode — showing cached data',
      child: Container(
        width: double.infinity,
        color: OkTokens.colorStatusWarning,
        padding: const EdgeInsets.symmetric(
          vertical: OkTokens.spacingXs,
          horizontal: OkTokens.spacingMd,
        ),
        child: const Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.wifi_off, size: 16, color: Colors.white),
            SizedBox(width: OkTokens.spacingXs),
            Text(
              'You are offline — showing cached data',
              style: TextStyle(color: Colors.white, fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

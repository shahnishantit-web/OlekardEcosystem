import 'package:flutter/material.dart';
import '../ok_theme_extension.dart';
import '../tokens.dart';

class OkErrorView extends StatelessWidget {
  final String message;
  final VoidCallback? onRetry;

  const OkErrorView({super.key, required this.message, this.onRetry});

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>();
    final errorColor = ok?.errorFg ?? OkTokens.colorStatusError;
    final textColor = ok?.appSurface != null
        ? Theme.of(context).colorScheme.onSurface
        : Colors.white;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(OkTokens.spacingLg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 48, color: errorColor),
            const SizedBox(height: OkTokens.spacingMd),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: OkTokens.fontSizeBase,
                color: textColor,
              ),
            ),
            if (onRetry != null) ...[
              const SizedBox(height: OkTokens.spacingMd),
              TextButton(
                onPressed: onRetry,
                child: const Text('Retry'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

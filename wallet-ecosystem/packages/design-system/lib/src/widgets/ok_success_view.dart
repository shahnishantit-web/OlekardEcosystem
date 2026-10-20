import 'package:flutter/material.dart';
import '../ok_theme_extension.dart';
import '../tokens.dart';

class OkSuccessView extends StatelessWidget {
  final String title;
  final String referenceNumber;
  final String? actionLabel;
  final VoidCallback? onAction;

  const OkSuccessView({
    super.key,
    required this.title,
    required this.referenceNumber,
    this.actionLabel,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>();
    final successColor = ok?.successFg ?? OkTokens.colorStatusSuccess;
    final primaryColor = ok?.primary ?? OkTokens.colorGreenPrimary;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(OkTokens.spacingLg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.check_circle_outline, size: 72, color: successColor),
            const SizedBox(height: OkTokens.spacingMd),
            Text(
              title,
              style: TextStyle(
                fontSize: OkTokens.fontSize2xl,
                fontWeight: OkTokens.fontWeightBold,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: OkTokens.spacingSm),
            Chip(
              label: Text(
                referenceNumber,
                style: TextStyle(
                  fontSize: OkTokens.fontSizeSm,
                  fontWeight: OkTokens.fontWeightMedium,
                  color: primaryColor,
                ),
              ),
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: OkTokens.spacingLg),
              ElevatedButton(
                onPressed: onAction,
                child: Text(actionLabel!),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

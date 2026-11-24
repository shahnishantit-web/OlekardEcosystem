import 'package:design_system/design_system.dart';
import 'package:flutter/material.dart';

export 'package:design_system/design_system.dart' show OkPinPadWidget;

/// Agent-facing adapter that preserves the existing PIN widget API while
/// using the shared accessible Flutter text-input bridge.
class PinPadWidget extends StatelessWidget {
  final void Function(String pin) onCompleted;
  final int length;
  final String? errorText;
  final bool enabled;
  final bool clearOnCompleted;

  const PinPadWidget({
    super.key,
    required this.onCompleted,
    this.length = 6,
    this.errorText,
    this.enabled = true,
    this.clearOnCompleted = true,
  });

  @override
  Widget build(BuildContext context) => Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          OkPinPadWidget(
            onCompleted: onCompleted,
            pinLength: length,
            enabled: enabled,
            clearOnCompleted: clearOnCompleted,
          ),
          if (errorText != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                errorText!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ),
        ],
      );
}

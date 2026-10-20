import 'package:flutter/material.dart';

enum BadgeVariant { neutral, success, warning, error, info }

class AppBadge extends StatelessWidget {
  const AppBadge({
    super.key,
    required this.label,
    this.variant = BadgeVariant.neutral,
  });

  final String label;
  final BadgeVariant variant;

  Color _color() => switch (variant) {
    BadgeVariant.success => Colors.green,
    BadgeVariant.warning => Colors.orange,
    BadgeVariant.error   => Colors.red,
    BadgeVariant.info    => Colors.blue,
    BadgeVariant.neutral => Colors.grey,
  };

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: label,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: _color().withOpacity(0.15),
          border: Border.all(color: _color()),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(
          label,
          style: TextStyle(color: _color(), fontSize: 12, fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../../../../core/theme/design_tokens.dart';

class EventCategoryStyle {
  const EventCategoryStyle(
      {required this.background, required this.foreground});

  final Color background;
  final Color foreground;

  static EventCategoryStyle resolve(String? category, Brightness brightness) {
    final dark = brightness == Brightness.dark;
    final colors = switch (category?.trim().toLowerCase()) {
      'community' => (
          const Color(0xFFDDF3EC),
          const Color(0xFF145C4A),
          const Color(0xFF15352D),
          const Color(0xFFA7F3D0)
        ),
      'music' => (
          const Color(0xFFFCE7F3),
          const Color(0xFF9D174D),
          const Color(0xFF45152D),
          const Color(0xFFF9A8D4)
        ),
      'sports' => (
          const Color(0xFFDBEAFE),
          const Color(0xFF1E40AF),
          const Color(0xFF172C55),
          const Color(0xFFBFDBFE)
        ),
      'club_game' => (
          const Color(0xFFFFE8C2),
          const Color(0xFF854D0E),
          const Color(0xFF422B14),
          const Color(0xFFFCD34D)
        ),
      'business' => (
          const Color(0xFFEDE9FE),
          const Color(0xFF5B21B6),
          const Color(0xFF30204C),
          const Color(0xFFDDD6FE)
        ),
      'arts' => (
          const Color(0xFFFFE4D6),
          const Color(0xFF9A3412),
          const Color(0xFF4A251A),
          const Color(0xFFFDBA9A)
        ),
      'family' => (
          const Color(0xFFE4F2D3),
          const Color(0xFF3F6212),
          const Color(0xFF29371B),
          const Color(0xFFD9F99D)
        ),
      _ => (
          AppColors.infoBg,
          AppColors.primaryDark,
          const Color(0xFF1E293B),
          const Color(0xFFBFDBFE)
        ),
    };

    return EventCategoryStyle(
      background: dark ? colors.$3 : colors.$1,
      foreground: dark ? colors.$4 : colors.$2,
    );
  }
}

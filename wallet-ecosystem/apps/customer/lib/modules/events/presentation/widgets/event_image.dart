import 'package:flutter/material.dart';

import '../../../../core/theme/design_tokens.dart';

class EventImage extends StatelessWidget {
  const EventImage({
    required this.imageUrl,
    required this.width,
    required this.height,
    this.eventId,
    this.eventCategory,
    this.label = 'Event image',
    this.iconSize = 32,
    this.borderRadius = 12,
    super.key,
  });

  final String? imageUrl;
  final double width;
  final double height;
  final String? eventId;
  final String? eventCategory;
  final String label;
  final double iconSize;
  final double borderRadius;

  @override
  Widget build(BuildContext context) {
    final uri = Uri.tryParse(imageUrl?.trim() ?? '');
    final hasValidUrl = uri != null &&
        uri.hasAuthority &&
        const {'http', 'https'}.contains(uri.scheme.toLowerCase());

    return Semantics(
      image: true,
      label: label,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(borderRadius),
        child: SizedBox(
          width: width,
          height: height,
          child: hasValidUrl
              ? Image.network(
                  uri.toString(),
                  width: width,
                  height: height,
                  fit: BoxFit.cover,
                  gaplessPlayback: true,
                  loadingBuilder: (context, child, progress) =>
                      progress == null ? child : _placeholder(context),
                  errorBuilder: (context, error, stackTrace) =>
                      _placeholder(context),
                )
              : _placeholder(context),
        ),
      ),
    );
  }

  Widget _placeholder(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final category = eventCategory?.trim().toLowerCase();
    final asset = switch (category) {
      'community' => 'assets/events/community-fair.png',
      'music' => 'assets/events/live-music.png',
      'sports' || 'club_game' => 'assets/events/sports-day.png',
      'arts' => 'assets/events/cultural-event.png',
      'family' => 'assets/events/community-fair.png',
      _ => null,
    };

    if (asset == null) {
      return ColoredBox(
        color: AppColors.secondary,
        child: Center(
          child: Icon(
            category == 'business'
                ? Icons.business_center_outlined
                : Icons.event_available_outlined,
            size: iconSize,
            color: colors.primary,
          ),
        ),
      );
    }

    return Image.asset(
      asset,
      width: width,
      height: height,
      fit: BoxFit.cover,
      errorBuilder: (context, error, stackTrace) => ColoredBox(
        color: AppColors.secondary,
        child: Center(
          child: Icon(
            Icons.event_available_outlined,
            size: iconSize,
            color: colors.primary,
          ),
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

class EventImage extends StatelessWidget {
  const EventImage({
    required this.imageUrl,
    required this.width,
    required this.height,
    this.eventId,
    this.label = 'Event image',
    this.iconSize = 32,
    super.key,
  });

  final String? imageUrl;
  final double width;
  final double height;
  final String? eventId;
  final String label;
  final double iconSize;

  static const _dummyImages = [
    'assets/events/community-fair.png',
    'assets/events/live-music.png',
    'assets/events/sports-day.png',
    'assets/events/cultural-event.png',
  ];

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
        borderRadius: BorderRadius.circular(12),
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
    final seed = eventId ?? label;
    final imageIndex = seed.codeUnits.fold<int>(0, (sum, unit) => sum + unit) %
        _dummyImages.length;

    return Image.asset(
      _dummyImages[imageIndex],
      width: width,
      height: height,
      fit: BoxFit.cover,
      errorBuilder: (context, error, stackTrace) => ColoredBox(
        color: colors.surfaceContainerHighest,
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

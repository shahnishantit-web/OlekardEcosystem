import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import '../ok_theme_extension.dart';
import '../tokens.dart';

class OkLoadingShimmer extends StatelessWidget {
  final double width;
  final double height;
  final BorderRadius? borderRadius;

  const OkLoadingShimmer({
    super.key,
    required this.width,
    required this.height,
    this.borderRadius,
  });

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>();
    final baseColor = ok?.appSurface ?? OkTokens.colorGreenSurface;
    final highlightColor = baseColor.withValues(alpha: 0.6);

    return Shimmer.fromColors(
      baseColor: baseColor,
      highlightColor: highlightColor,
      child: Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: baseColor,
          borderRadius: borderRadius ?? BorderRadius.circular(4),
        ),
      ),
    );
  }
}

class OkLoadingShimmerList extends StatelessWidget {
  final int itemCount;
  final double itemHeight;

  const OkLoadingShimmerList({
    super.key,
    required this.itemCount,
    this.itemHeight = 64,
  });

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      physics: const NeverScrollableScrollPhysics(),
      shrinkWrap: true,
      itemCount: itemCount,
      separatorBuilder: (_, __) => const SizedBox(height: OkTokens.spacingSm),
      itemBuilder: (_, __) => OkLoadingShimmer(
        width: double.infinity,
        height: itemHeight,
        borderRadius: BorderRadius.circular(8),
      ),
    );
  }
}

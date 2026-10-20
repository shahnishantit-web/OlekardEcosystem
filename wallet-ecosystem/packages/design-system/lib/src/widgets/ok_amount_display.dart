import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../ok_theme_extension.dart';
import '../tokens.dart';

class OkAmountDisplay extends StatelessWidget {
  final int amountMinorUnits;
  final String currencyCode;
  final bool? isCredit;
  final TextStyle? style;

  const OkAmountDisplay({
    super.key,
    required this.amountMinorUnits,
    required this.currencyCode,
    this.isCredit,
    this.style,
  });

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>();
    final major = amountMinorUnits / 100.0;
    final formatted = NumberFormat.currency(
      symbol: '$currencyCode ',
      decimalDigits: 2,
    ).format(major);

    Color? signColor;
    if (isCredit == true) {
      signColor = ok?.successFg ?? OkTokens.colorStatusSuccess;
    } else if (isCredit == false) {
      signColor = ok?.errorFg ?? OkTokens.colorStatusError;
    }

    final effectiveStyle = (style ?? const TextStyle()).copyWith(
      color: signColor ?? style?.color,
    );

    return Text(formatted, style: effectiveStyle);
  }
}

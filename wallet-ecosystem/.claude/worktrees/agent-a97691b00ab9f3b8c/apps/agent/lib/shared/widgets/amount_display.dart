import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import 'package:wallet_agent/core/theme/design_tokens.dart';

enum AmountSize { large, medium, small }

class AmountDisplay extends StatelessWidget {
  final int minorUnits;
  final String currencyCode;
  final AmountSize size;
  final Color? color;
  final bool showSign;

  const AmountDisplay({
    super.key,
    required this.minorUnits,
    required this.currencyCode,
    this.size = AmountSize.medium,
    this.color,
    this.showSign = false,
  });

  @override
  Widget build(BuildContext context) {
    final formatted = _format();
    final style = switch (size) {
      AmountSize.large => AppTypography.amountLarge,
      AmountSize.medium => AppTypography.amountMedium,
      AmountSize.small => AppTypography.amountSmall,
    };

    return Text(
      formatted,
      style: style.copyWith(color: color),
      semanticsLabel: '$formatted $currencyCode',
    );
  }

  String _format() {
    final value = minorUnits / 100.0;
    final fmt = NumberFormat.currency(
      symbol: '$currencyCode ',
      decimalDigits: 2,
    );
    final formatted = fmt.format(value);
    if (showSign && minorUnits > 0) return '+$formatted';
    return formatted;
  }
}

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

class AmountDisplay extends StatelessWidget {
  final int minorUnits;
  final String currencyCode;
  final TextStyle? style;

  const AmountDisplay({
    super.key,
    required this.minorUnits,
    required this.currencyCode,
    this.style,
  });

  @override
  Widget build(BuildContext context) {
    final major = minorUnits / 100.0;
    final formatted = NumberFormat.currency(
      symbol: '$currencyCode ',
      decimalDigits: 2,
    ).format(major);

    return Text(formatted, style: style);
  }
}

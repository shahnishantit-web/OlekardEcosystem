import 'package:flutter/material.dart';

/// Numeric keypad used by Agent PIN entry, create, and change screens.
/// No biometric button — Agent Phase 18 scope excludes biometric.
class PinKeypad extends StatelessWidget {
  const PinKeypad({
    super.key,
    required this.onDigit,
    required this.onBackspace,
  });

  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;

  static const _layout = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['', '0', 'del'],
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      children: _layout.map((row) {
        return Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: row
              .map((key) => _KeyCell(
                    label: key,
                    onDigit: onDigit,
                    onBackspace: onBackspace,
                  ))
              .toList(),
        );
      }).toList(),
    );
  }
}

class _KeyCell extends StatelessWidget {
  const _KeyCell({
    required this.label,
    required this.onDigit,
    required this.onBackspace,
  });

  final String label;
  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;

  @override
  Widget build(BuildContext context) {
    if (label.isEmpty) {
      return const SizedBox(width: 80, height: 80);
    }

    if (label == 'del') {
      return SizedBox(
        width: 80,
        height: 80,
        child: Semantics(
          button: true,
          label: 'Delete',
          child: IconButton(
            icon: const Icon(Icons.backspace_outlined, size: 28),
            onPressed: onBackspace,
          ),
        ),
      );
    }

    return Semantics(
      button: true,
      label: label,
      child: InkWell(
        onTap: () => onDigit(label),
        borderRadius: BorderRadius.circular(40),
        child: Container(
          width: 80,
          height: 80,
          alignment: Alignment.center,
          child: Text(
            label,
            style: Theme.of(context).textTheme.headlineMedium,
          ),
        ),
      ),
    );
  }
}

/// Displays PIN progress as filled/empty circles.
class PinDots extends StatelessWidget {
  const PinDots({
    super.key,
    required this.length,
    required this.filled,
    this.hasError = false,
  });

  final int length;
  final int filled;
  final bool hasError;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(length, (i) {
        final isFilled = i < filled;
        return AnimatedContainer(
          duration: const Duration(milliseconds: 120),
          width: 16,
          height: 16,
          margin: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: hasError
                ? Theme.of(context).colorScheme.error
                : isFilled
                    ? Theme.of(context).colorScheme.primary
                    : Theme.of(context).colorScheme.outlineVariant,
          ),
        );
      }),
    );
  }
}

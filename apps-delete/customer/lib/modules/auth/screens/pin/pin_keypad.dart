import 'package:flutter/material.dart';

/// Shared numeric keypad used by PIN entry, create, and change screens.
/// Emits individual digit taps and backspace events via callbacks.
class PinKeypad extends StatelessWidget {
  const PinKeypad({
    super.key,
    required this.onDigit,
    required this.onBackspace,
    this.onBiometric,
  });

  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;
  final VoidCallback? onBiometric;

  static const _layout = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['bio', '0', 'del'],
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      children: _layout.map((row) {
        return Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: row.map((key) => _KeyCell(
                label: key,
                onDigit: onDigit,
                onBackspace: onBackspace,
                onBiometric: onBiometric,
              )).toList(),
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
    required this.onBiometric,
  });

  final String label;
  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;
  final VoidCallback? onBiometric;

  @override
  Widget build(BuildContext context) {
    if (label == 'bio') {
      return _buildActionCell(
        context,
        child: onBiometric != null
            ? Semantics(
                button: true,
                label: 'Use biometric',
                child: IconButton(
                  icon: const Icon(Icons.fingerprint, size: 32),
                  onPressed: onBiometric,
                ),
              )
            : const SizedBox(width: 80, height: 80),
      );
    }

    if (label == 'del') {
      return _buildActionCell(
        context,
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

  Widget _buildActionCell(BuildContext context, {required Widget child}) {
    return SizedBox(width: 80, height: 80, child: child);
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

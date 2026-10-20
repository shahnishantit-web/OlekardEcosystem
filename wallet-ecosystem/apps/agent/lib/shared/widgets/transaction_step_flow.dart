import 'package:flutter/material.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

class TransactionStepFlow extends StatelessWidget {
  final List<String> steps;
  final int currentStep;
  final Widget child;

  const TransactionStepFlow({
    super.key,
    required this.steps,
    required this.currentStep,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _StepIndicator(steps: steps, currentStep: currentStep),
        const SizedBox(height: AppSpacing.md),
        Expanded(child: child),
      ],
    );
  }
}

class _StepIndicator extends StatelessWidget {
  final List<String> steps;
  final int currentStep;

  const _StepIndicator({required this.steps, required this.currentStep});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.md,
        vertical: AppSpacing.sm,
      ),
      child: Column(
        children: [
          Row(
            children: [
              for (int i = 0; i < steps.length; i++) ...[
                _StepDot(
                  index: i,
                  currentStep: currentStep,
                  total: steps.length,
                ),
                if (i < steps.length - 1)
                  Expanded(
                    child: Container(
                      height: 2,
                      color: i < currentStep
                          ? AppColors.success
                          : AppColors.outline,
                    ),
                  ),
              ],
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          if (currentStep < steps.length)
            Text(
              steps[currentStep],
              style: AppTypography.bodySmall.copyWith(
                color: AppColors.primary,
                fontWeight: FontWeight.w600,
              ),
              textAlign: TextAlign.center,
            ),
        ],
      ),
    );
  }
}

class _StepDot extends StatelessWidget {
  final int index;
  final int currentStep;
  final int total;

  const _StepDot({
    required this.index,
    required this.currentStep,
    required this.total,
  });

  @override
  Widget build(BuildContext context) {
    final isDone = index < currentStep;
    final isCurrent = index == currentStep;

    return Semantics(
      label: isDone
          ? 'Step ${index + 1} of $total: done'
          : isCurrent
              ? 'Step ${index + 1} of $total: current'
              : 'Step ${index + 1} of $total: upcoming',
      child: Container(
        width: 28,
        height: 28,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: isDone
              ? AppColors.success
              : isCurrent
                  ? AppColors.primary
                  : AppColors.surface,
          border: Border.all(
            color: isDone
                ? AppColors.success
                : isCurrent
                    ? AppColors.primary
                    : AppColors.outline,
            width: 2,
          ),
        ),
        child: Center(
          child: isDone
              ? const Icon(Icons.check, size: 14, color: Colors.white)
              : isCurrent
                  ? Container(
                      width: 10,
                      height: 10,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: Colors.white,
                      ),
                    )
                  : null,
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/design_tokens.dart';
import '../../domain/entities/refund_status.dart';
import '../refund_providers.dart';

/// Displays the refund policy before payment confirmation and allows consumers
/// to track their refund status after a refund has been requested.
///
/// Place this widget in the receipt detail screen or order detail screen.
class RefundRequestWidget extends ConsumerWidget {
  final String orderId;

  const RefundRequestWidget({
    required this.orderId,
    super.key,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final refundAsync = ref.watch(refundStatusProvider(orderId));

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.replay_outlined, size: 18),
                SizedBox(width: AppSpacing.sm),
                Text('Refund', style: AppTypography.label),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            refundAsync.when(
              loading: () => const Center(
                child: Padding(
                  padding: EdgeInsets.symmetric(vertical: AppSpacing.md),
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
              error: (err, _) => Text(
                'Unable to load refund status.',
                style: AppTypography.caption.copyWith(color: AppColors.error),
              ),
              data: (state) => _RefundContent(
                orderId: orderId,
                state: state,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RefundContent extends ConsumerWidget {
  final String orderId;
  final RefundState state;

  const _RefundContent({required this.orderId, required this.state});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (state.requests.isNotEmpty) {
      return _RefundStatusList(requests: state.requests);
    }

    if (!state.isEligible) {
      return _RefundPolicy(policy: state.policyText);
    }

    return _RefundRequestForm(orderId: orderId, policy: state.policyText);
  }
}

class _RefundPolicy extends StatelessWidget {
  final String policy;
  const _RefundPolicy({required this.policy});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Refund Policy',
          style: AppTypography.label.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: AppSpacing.xs),
        Text(
          policy.isNotEmpty ? policy : 'This purchase is not eligible for a refund.',
          style: AppTypography.caption,
        ),
      ],
    );
  }
}

class _RefundRequestForm extends ConsumerStatefulWidget {
  final String orderId;
  final String policy;

  const _RefundRequestForm({required this.orderId, required this.policy});

  @override
  ConsumerState<_RefundRequestForm> createState() => _RefundRequestFormState();
}

class _RefundRequestFormState extends ConsumerState<_RefundRequestForm> {
  bool _policyAgreed = false;
  final _reasonController = TextEditingController();
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_policyAgreed || _reasonController.text.trim().isEmpty) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await ref
          .read(refundSubmitProvider(widget.orderId).notifier)
          .request(reason: _reasonController.text.trim());
    } catch (e) {
      setState(() => _error = 'Failed to submit refund request. Please try again.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _RefundPolicy(policy: widget.policy),
        const SizedBox(height: AppSpacing.md),
        Text(
          'Reason for Refund',
          style: AppTypography.label.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: AppSpacing.xs),
        TextField(
          controller: _reasonController,
          maxLines: 3,
          maxLength: 500,
          decoration: const InputDecoration(
            hintText: 'Describe why you are requesting a refund…',
            border: OutlineInputBorder(),
            contentPadding: EdgeInsets.all(AppSpacing.sm),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Checkbox(
              value: _policyAgreed,
              onChanged: (v) => setState(() => _policyAgreed = v ?? false),
              semanticLabel: 'I have read and agree to the refund policy',
            ),
            const Expanded(
              child: Padding(
                padding: EdgeInsets.only(top: 12),
                child: Text(
                  'I have read and agree to the refund policy.',
                  style: AppTypography.caption,
                ),
              ),
            ),
          ],
        ),
        if (_error != null) ...[
          const SizedBox(height: AppSpacing.sm),
          Text(
            _error!,
            style: AppTypography.caption.copyWith(color: AppColors.error),
          ),
        ],
        const SizedBox(height: AppSpacing.md),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed:
                _policyAgreed && !_submitting ? _submit : null,
            child: _submitting
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Text('Request Refund'),
          ),
        ),
      ],
    );
  }
}

class _RefundStatusList extends StatelessWidget {
  final List<RefundRequest> requests;
  const _RefundStatusList({required this.requests});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: requests
          .map((r) => Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: _RefundStatusTile(request: r),
              ))
          .toList(),
    );
  }
}

class _RefundStatusTile extends StatelessWidget {
  final RefundRequest request;
  const _RefundStatusTile({required this.request});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
        color: _statusColour(request.status).withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: _statusColour(request.status).withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          Icon(_statusIcon(request.status), color: _statusColour(request.status), size: 20),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Refund ${request.status.label}',
                  style: AppTypography.label.copyWith(
                    color: _statusColour(request.status),
                  ),
                ),
                Text(
                  'Ref: ${request.referenceNumber}',
                  style: AppTypography.caption.copyWith(color: AppColors.textSecondary),
                ),
              ],
            ),
          ),
          Text(
            'BWP ${(request.amountMinor / 100).toStringAsFixed(2)}',
            style: AppTypography.label,
          ),
        ],
      ),
    );
  }

  Color _statusColour(RefundStatus status) {
    return switch (status) {
      RefundStatus.pending  => AppColors.warning,
      RefundStatus.approved => AppColors.info,
      RefundStatus.settled  => AppColors.success,
      RefundStatus.rejected => AppColors.error,
      RefundStatus.failed   => AppColors.textSecondary,
    };
  }

  IconData _statusIcon(RefundStatus status) {
    return switch (status) {
      RefundStatus.pending  => Icons.hourglass_empty,
      RefundStatus.approved => Icons.check_circle_outline,
      RefundStatus.settled  => Icons.check_circle,
      RefundStatus.rejected => Icons.cancel_outlined,
      RefundStatus.failed   => Icons.error_outline,
    };
  }
}

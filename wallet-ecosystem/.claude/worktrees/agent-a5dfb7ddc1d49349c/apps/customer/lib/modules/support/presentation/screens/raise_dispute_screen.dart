import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/primary_button.dart';

class RaiseDisputeScreen extends ConsumerStatefulWidget {
  const RaiseDisputeScreen({super.key});

  @override
  ConsumerState<RaiseDisputeScreen> createState() => _RaiseDisputeScreenState();
}

class _RaiseDisputeScreenState extends ConsumerState<RaiseDisputeScreen> {
  final _refCtrl = TextEditingController();
  final _reasonCtrl = TextEditingController();
  bool _isLoading = false;
  String? _error;

  @override
  void dispose() {
    _refCtrl.dispose();
    _reasonCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Raise Dispute')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Use this form to dispute a transaction you did not authorize or believe was processed incorrectly.',
              style: AppTypography.body,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextFormField(
              controller: _refCtrl,
              decoration: const InputDecoration(
                labelText: 'Transaction Reference',
                hintText: 'e.g. TXN-20241001-XXXX',
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            TextFormField(
              controller: _reasonCtrl,
              decoration: const InputDecoration(
                labelText: 'Reason for Dispute',
                alignLabelWithHint: true,
              ),
              maxLines: 4,
              maxLength: 1000,
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(_error!, style: const TextStyle(color: AppColors.error)),
            ],
            const SizedBox(height: AppSpacing.lg),
            PrimaryButton(
              label: 'Submit Dispute',
              isLoading: _isLoading,
              onPressed: _submit,
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _submit() async {
    if (_refCtrl.text.trim().isEmpty || _reasonCtrl.text.trim().isEmpty) {
      setState(() => _error = 'All fields are required.');
      return;
    }
    setState(() { _isLoading = true; _error = null; });
    try {
      final dispute = await ref.read(supportRepositoryProvider).raiseDispute(
            RaiseDisputeRequest(
              transactionReference: _refCtrl.text.trim(),
              reason: _reasonCtrl.text.trim(),
            ),
          );
      ref.invalidate(disputesProvider(1));
      if (mounted) context.replace('/support/disputes/${dispute.id}');
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }
}

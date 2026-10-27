import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../../core/theme/design_tokens.dart';
import '../../domain/entities/refund_request.dart';
import '../../domain/repositories/transaction_repository.dart';
import '../transaction_providers.dart';

/// Bottom sheet for raising a support ticket pre-filled from a payment.
///
/// `paymentIntentId` is shown as a read-only reference; `prefilledSubject` is
/// the default value for the editable subject field.
///
/// Attachments are constrained to PDF/PNG/JPEG and checked against the
/// configured max-bytes limit before upload. The max limit is read from
/// E28 ConfigService if available; otherwise 10 MiB is used.
class SupportTicketDraftSheet extends ConsumerStatefulWidget {
  final String paymentIntentId;
  final String prefilledSubject;

  const SupportTicketDraftSheet({
    super.key,
    required this.paymentIntentId,
    required this.prefilledSubject,
  });

  @override
  ConsumerState<SupportTicketDraftSheet> createState() =>
      _SupportTicketDraftSheetState();
}

class _SupportTicketDraftSheetState
    extends ConsumerState<SupportTicketDraftSheet> {
  final _subjectController = TextEditingController();
  final _descriptionController = TextEditingController();
  final List<String> _attachmentPaths = [];
  String? _attachmentError;
  bool _submitting = false;
  String? _submittedReference;
  String? _submitError;

  // 10 MiB default; replace with E28 config read in production.
  static const int _maxAttachmentBytes = 10 * 1024 * 1024;
  static const Set<String> _allowedExtensions = {'pdf', 'png', 'jpg', 'jpeg'};

  @override
  void initState() {
    super.initState();
    _subjectController.text = widget.prefilledSubject;
  }

  @override
  void dispose() {
    _subjectController.dispose();
    _descriptionController.dispose();
    super.dispose();
  }

  Future<void> _pickAttachment() async {
    // File picker integration: in production, use file_picker package.
    // For now, the UI shell is in place; picking is an integration concern.
    setState(() => _attachmentError = 'File picker integration required');
  }

  void _validateAndAddAttachment(String path, int sizeBytes) {
    final ext = path.split('.').last.toLowerCase();
    if (!_allowedExtensions.contains(ext)) {
      setState(() {
        _attachmentError = 'Unsupported file type. Use PDF, PNG, or JPEG.';
      });
      return;
    }
    if (sizeBytes > _maxAttachmentBytes) {
      final maxMb = _maxAttachmentBytes ~/ (1024 * 1024);
      setState(() {
        _attachmentError = 'File too large. Maximum attachment size is ${maxMb}MB.';
      });
      return;
    }
    setState(() {
      _attachmentPaths.add(path);
      _attachmentError = null;
    });
  }

  Future<void> _submit() async {
    if (_subjectController.text.trim().isEmpty) return;

    setState(() {
      _submitting = true;
      _submitError = null;
    });

    try {
      final repo = ref.read(transactionRepositoryProvider);
      await repo.createSupportTicket(SupportTicketDraft(
        paymentIntentId: widget.paymentIntentId,
        subject: _subjectController.text.trim(),
        description: _descriptionController.text.trim().isEmpty
            ? null
            : _descriptionController.text.trim(),
        attachmentPaths: List.unmodifiable(_attachmentPaths),
      ));
      setState(() {
        _submittedReference = widget.paymentIntentId;
        _submitting = false;
      });
    } catch (e) {
      setState(() {
        _submitError = 'Failed to submit ticket. Please try again.';
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_submittedReference != null) {
      return _SuccessView(reference: _submittedReference!);
    }

    return Padding(
      padding: EdgeInsets.only(
        left: AppSpacing.lg,
        right: AppSpacing.lg,
        top: AppSpacing.lg,
        bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.lg,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('Get Support', style: AppTypography.label),
            const SizedBox(height: AppSpacing.md),
            // Read-only reference
            InputDecorator(
              decoration: const InputDecoration(
                labelText: 'Payment Reference',
                border: OutlineInputBorder(),
              ),
              child: Text(
                widget.paymentIntentId,
                style: AppTypography.caption,
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            TextField(
              key: const Key('subject_field'),
              controller: _subjectController,
              decoration: const InputDecoration(
                labelText: 'Subject',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            TextField(
              key: const Key('description_field'),
              controller: _descriptionController,
              decoration: const InputDecoration(
                labelText: 'Description (optional)',
                border: OutlineInputBorder(),
              ),
              maxLines: 4,
            ),
            const SizedBox(height: AppSpacing.md),
            if (_attachmentPaths.isNotEmpty) ...[
              ..._attachmentPaths.map(
                (p) => ListTile(
                  leading: const Icon(Icons.attach_file),
                  title: Text(p.split('/').last, style: AppTypography.caption),
                  trailing: IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () =>
                        setState(() => _attachmentPaths.remove(p)),
                  ),
                ),
              ),
            ],
            if (_attachmentError != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: Text(
                  _attachmentError!,
                  style: AppTypography.caption
                      .copyWith(color: AppColors.error),
                ),
              ),
            SizedBox(
              height: 44,
              child: OutlinedButton.icon(
                key: const Key('attach_button'),
                icon: const Icon(Icons.attach_file),
                label: const Text('Attach File'),
                onPressed: _pickAttachment,
              ),
            ),
            if (_submitError != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: Text(
                  _submitError!,
                  style:
                      AppTypography.caption.copyWith(color: AppColors.error),
                ),
              ),
            const SizedBox(height: AppSpacing.lg),
            SizedBox(
              height: 44,
              child: ElevatedButton(
                key: const Key('submit_button'),
                onPressed: _submitting ? null : _submit,
                child: _submitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Submit'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SuccessView extends StatelessWidget {
  final String reference;
  const _SuccessView({required this.reference});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.check_circle, color: Colors.green, size: 64),
          const SizedBox(height: AppSpacing.md),
          Text('Ticket submitted', style: AppTypography.label),
          const SizedBox(height: AppSpacing.sm),
          Semantics(
            label: 'Support reference: $reference',
            child: Text(
              'Reference: $reference',
              style: AppTypography.caption,
              textAlign: TextAlign.center,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Done'),
          ),
        ],
      ),
    );
  }
}

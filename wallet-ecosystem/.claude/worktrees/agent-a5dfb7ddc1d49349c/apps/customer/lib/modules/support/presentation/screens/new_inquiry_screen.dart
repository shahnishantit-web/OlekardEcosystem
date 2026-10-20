import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/primary_button.dart';

const _categories = [
  'Account',
  'Transaction',
  'Card',
  'Wallet',
  'Technical',
  'Other',
];

class NewInquiryScreen extends ConsumerStatefulWidget {
  const NewInquiryScreen({super.key});

  @override
  ConsumerState<NewInquiryScreen> createState() => _NewInquiryScreenState();
}

class _NewInquiryScreenState extends ConsumerState<NewInquiryScreen> {
  final _subjectCtrl = TextEditingController();
  final _messageCtrl = TextEditingController();
  String _selectedCategory = _categories.first;
  bool _isLoading = false;
  String? _error;

  @override
  void dispose() {
    _subjectCtrl.dispose();
    _messageCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('New Inquiry')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextFormField(
              controller: _subjectCtrl,
              decoration: const InputDecoration(labelText: 'Subject'),
              maxLength: 100,
            ),
            const SizedBox(height: AppSpacing.md),
            DropdownButtonFormField<String>(
              key: ValueKey(_selectedCategory),
              initialValue: _selectedCategory,
              decoration: const InputDecoration(labelText: 'Category'),
              items: _categories
                  .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                  .toList(),
              onChanged: (v) => setState(() => _selectedCategory = v ?? _categories.first),
            ),
            const SizedBox(height: AppSpacing.md),
            TextFormField(
              controller: _messageCtrl,
              decoration: const InputDecoration(labelText: 'Message', alignLabelWithHint: true),
              maxLines: 5,
              maxLength: 2000,
            ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(_error!, style: const TextStyle(color: AppColors.error)),
            ],
            const SizedBox(height: AppSpacing.lg),
            PrimaryButton(
              label: 'Submit Inquiry',
              isLoading: _isLoading,
              onPressed: _submit,
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _submit() async {
    if (_subjectCtrl.text.trim().isEmpty || _messageCtrl.text.trim().isEmpty) {
      setState(() => _error = 'Subject and message are required.');
      return;
    }
    setState(() { _isLoading = true; _error = null; });
    try {
      final ticket = await ref.read(supportRepositoryProvider).createTicket(
            CreateTicketRequest(
              subject: _subjectCtrl.text.trim(),
              category: _selectedCategory,
              message: _messageCtrl.text.trim(),
            ),
          );
      ref.invalidate(ticketsProvider(1));
      if (mounted) context.replace('/support/tickets/${ticket.id}');
    } catch (e) {
      setState(() => _error = e.toString());
    } finally {
      setState(() => _isLoading = false);
    }
  }
}

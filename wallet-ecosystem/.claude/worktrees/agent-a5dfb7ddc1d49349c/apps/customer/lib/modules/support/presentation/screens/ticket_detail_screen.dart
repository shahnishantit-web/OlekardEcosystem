import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../support_providers.dart';
import '../../domain/entities/support_ticket.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class TicketDetailScreen extends ConsumerStatefulWidget {
  final String ticketId;
  const TicketDetailScreen({super.key, required this.ticketId});

  @override
  ConsumerState<TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends ConsumerState<TicketDetailScreen> {
  final _replyCtrl = TextEditingController();
  bool _isReplying = false;

  @override
  void dispose() {
    _replyCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ticketAsync = ref.watch(ticketDetailProvider(widget.ticketId));

    return Scaffold(
      appBar: AppBar(title: const Text('Ticket Details')),
      body: ticketAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 4),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (ticket) => Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  Text(ticket.subject, style: AppTypography.headline),
                  Text('#${ticket.referenceNumber}', style: AppTypography.caption),
                  const Divider(height: AppSpacing.lg),
                  ...ticket.messages.map((m) => _MessageBubble(message: m)),
                ],
              ),
            ),
            if (ticket.status != TicketStatus.closed && ticket.status != TicketStatus.resolved)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Row(
                  children: [
                    Expanded(
                      child: TextFormField(
                        controller: _replyCtrl,
                        decoration: const InputDecoration(hintText: 'Write a reply...'),
                        maxLines: 2,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    _isReplying
                        ? const CircularProgressIndicator()
                        : IconButton.filled(
                            icon: const Icon(Icons.send),
                            onPressed: _sendReply,
                          ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _sendReply() async {
    if (_replyCtrl.text.trim().isEmpty) return;
    setState(() => _isReplying = true);
    try {
      await ref.read(supportRepositoryProvider).replyToTicket(
            widget.ticketId,
            _replyCtrl.text.trim(),
          );
      _replyCtrl.clear();
      ref.invalidate(ticketDetailProvider(widget.ticketId));
    } catch (_) {
      // error is logged; future enhancement: show snackbar
    } finally {
      setState(() => _isReplying = false);
    }
  }
}

class _MessageBubble extends StatelessWidget {
  final TicketMessage message;
  const _MessageBubble({required this.message});

  @override
  Widget build(BuildContext context) => Align(
        alignment: message.isFromSupport ? Alignment.centerLeft : Alignment.centerRight,
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: 4),
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.sm),
          decoration: BoxDecoration(
            color: message.isFromSupport
                ? Colors.grey.shade200
                : AppColors.primary.withValues(alpha: 0.15),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(message.body, style: AppTypography.body),
              const SizedBox(height: 2),
              Text(
                message.createdAt.toLocal().toString().substring(0, 16),
                style: AppTypography.caption.copyWith(color: Colors.grey),
              ),
            ],
          ),
        ),
      );
}


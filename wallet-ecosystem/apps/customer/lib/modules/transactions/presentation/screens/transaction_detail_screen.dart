import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import '../transaction_providers.dart';
import '../../application/payment_detail_notifier.dart';
import '../widgets/payment_status_row.dart';
import '../../domain/entities/payment_status_rows.dart';
import '../widgets/domain_deep_link_tile.dart';
import '../../../../../core/security/screenshot_guard.dart';
import '../../../../../core/theme/design_tokens.dart';
import '../../../../../shared/widgets/amount_display.dart';
import '../../../../../shared/widgets/error_view.dart';
import '../../../../../shared/widgets/loading_shimmer.dart';
import '../../../../../core/router/payment_routes.dart';
import 'support_ticket_sheet.dart';

class TransactionDetailScreen extends ConsumerStatefulWidget {
  final String id;
  const TransactionDetailScreen({super.key, required this.id});

  @override
  ConsumerState<TransactionDetailScreen> createState() =>
      _TransactionDetailScreenState();
}

class _TransactionDetailScreenState
    extends ConsumerState<TransactionDetailScreen> {
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final detailAsync = ref.watch(paymentDetailProvider(widget.id));

    return Scaffold(
      appBar: AppBar(title: const Text('Transaction Detail')),
      body: detailAsync.when(
        loading: () => Semantics(
          liveRegion: true,
          label: 'Loading...',
          child: const Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: ShimmerList(itemCount: 8),
          ),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (state) => _buildBody(context, state),
      ),
    );
  }

  Widget _buildBody(BuildContext context, PaymentDetailState state) {
    if (state is PaymentDetailLoading) {
      return Semantics(
        liveRegion: true,
        label: 'Loading...',
        child: const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 8),
        ),
      );
    }
    if (state is PaymentDetailError) {
      return ErrorView(message: state.message);
    }
    if (state is PaymentDetailLoaded) {
      return _DetailContent(
        state: state,
        intentId: widget.id,
        onInitiateRefund: () =>
            ref.read(paymentDetailProvider(widget.id).notifier).initiateRefund(),
        onOpenSupport: () =>
            ref.read(paymentDetailProvider(widget.id).notifier).openSupportDraft(),
      );
    }
    if (state is PaymentDetailRefundConfirm) {
      return _RefundConfirmSheet(
        item: state.item,
        onSubmit: (reason) => ref
            .read(paymentDetailProvider(widget.id).notifier)
            .submitRefund(reason: reason),
        onCancel: () =>
            ref.read(paymentDetailProvider(widget.id).notifier).cancel(),
      );
    }
    if (state is PaymentDetailRefundSubmitting) {
      return const Center(child: CircularProgressIndicator());
    }
    if (state is PaymentDetailSupportDraft) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          builder: (_) => SupportTicketDraftSheet(
            paymentIntentId: state.item.id,
            prefilledSubject: state.item.displayTitle,
          ),
        ).then((_) {
          if (mounted) {
            ref.read(paymentDetailProvider(widget.id).notifier).cancel();
          }
        });
      });
      // Keep the detail content visible behind the sheet.
      return _DetailContent(
        state: PaymentDetailLoaded(
          item: state.item,
          statusRows: PaymentStatusRows.fromItem(state.item),
          deepLink: state.item.domainDeepLink,
        ),
        intentId: widget.id,
        onInitiateRefund: () =>
            ref.read(paymentDetailProvider(widget.id).notifier).initiateRefund(),
        onOpenSupport: () =>
            ref.read(paymentDetailProvider(widget.id).notifier).openSupportDraft(),
      );
    }
    if (state is PaymentDetailRefundSubmitted) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.check_circle, color: Colors.green, size: 64),
            const SizedBox(height: AppSpacing.md),
            Text('Refund requested', style: AppTypography.label),
            const SizedBox(height: AppSpacing.md),
            OutlinedButton(
              onPressed: () =>
                  ref.read(paymentDetailProvider(widget.id).notifier).cancel(),
              child: const Text('Done'),
            ),
          ],
        ),
      );
    }
    return const SizedBox.shrink();
  }
}

class _DetailContent extends StatelessWidget {
  final PaymentDetailLoaded state;
  final String intentId;
  final VoidCallback onInitiateRefund;
  final VoidCallback onOpenSupport;

  const _DetailContent({
    required this.state,
    required this.intentId,
    required this.onInitiateRefund,
    required this.onOpenSupport,
  });

  @override
  Widget build(BuildContext context) {
    final item = state.item;
    final rows = state.statusRows;

    return ListView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      children: [
        _Row('Reference', item.referenceNumber),
        _Row('Type', item.purposeType.name),
        if (item.counterpartyName != null)
          _Row('Counterparty', item.counterpartyName!),
        AmountDisplay(
          amountMinorUnits: item.amountMinorUnits,
          currencyCode: item.currencyCode,
          style: AppTypography.label,
        ),
        if (item.maskedProviderReference != null)
          _Row('Provider Ref', item.maskedProviderReference!),
        if (item.description != null) _Row('Description', item.description!),
        _Row('Date', DateFormat.yMMMMd().add_jm().format(item.createdAt)),
        const Divider(height: AppSpacing.lg),
        Text('Status Details', style: AppTypography.caption),
        const SizedBox(height: AppSpacing.sm),
        PaymentStatusRow(label: 'Capture', value: rows.captureStatus),
        PaymentStatusRow(label: 'Accounting', value: rows.accountingStatus),
        PaymentStatusRow(label: 'Service', value: rows.serviceStatus),
        if (rows.refundStatus != null)
          PaymentStatusRow(label: 'Refund', value: rows.refundStatus!),
        if (state.deepLink != null) ...[
          const Divider(height: AppSpacing.lg),
          DomainDeepLinkTile(deepLink: state.deepLink!),
        ],
        const SizedBox(height: AppSpacing.lg),
        if (item.receiptAvailable)
          SizedBox(
            height: 44,
            child: OutlinedButton.icon(
              icon: const Icon(Icons.receipt_outlined),
              label: const Text('View Receipt'),
              onPressed: () =>
                  context.push(PaymentRoutes.receiptRoute(intentId)),
            ),
          ),
        const SizedBox(height: AppSpacing.sm),
        if (item.refundEligible)
          SizedBox(
            height: 44,
            child: OutlinedButton.icon(
              icon: const Icon(Icons.replay_outlined),
              label: const Text('Request Refund'),
              onPressed: onInitiateRefund,
            ),
          ),
        const SizedBox(height: AppSpacing.sm),
        SizedBox(
          height: 44,
          child: OutlinedButton.icon(
            icon: const Icon(Icons.support_agent_outlined),
            label: const Text('Get Support'),
            onPressed: onOpenSupport,
          ),
        ),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label,
                style: AppTypography.caption.copyWith(color: Colors.grey)),
            Flexible(
              child: Text(value,
                  style: AppTypography.label, textAlign: TextAlign.end),
            ),
          ],
        ),
      );
}

class _RefundConfirmSheet extends StatefulWidget {
  final dynamic item;
  final void Function(String reason) onSubmit;
  final VoidCallback onCancel;

  const _RefundConfirmSheet({
    required this.item,
    required this.onSubmit,
    required this.onCancel,
  });

  @override
  State<_RefundConfirmSheet> createState() => _RefundConfirmSheetState();
}

class _RefundConfirmSheetState extends State<_RefundConfirmSheet> {
  final _reasonController = TextEditingController();

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Request Refund', style: AppTypography.label),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _reasonController,
            decoration: const InputDecoration(
              labelText: 'Reason for refund',
              border: OutlineInputBorder(),
            ),
            maxLines: 3,
          ),
          const SizedBox(height: AppSpacing.lg),
          SizedBox(
            height: 44,
            child: ElevatedButton(
              onPressed: () {
                if (_reasonController.text.isNotEmpty) {
                  widget.onSubmit(_reasonController.text);
                }
              },
              child: const Text('Submit Refund Request'),
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextButton(
            onPressed: widget.onCancel,
            child: const Text('Cancel'),
          ),
        ],
      ),
    );
  }
}

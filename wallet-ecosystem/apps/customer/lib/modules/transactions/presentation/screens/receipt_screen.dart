import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../transaction_providers.dart';
import '../../application/receipt_access_notifier.dart';
import '../../../../../core/security/screenshot_guard.dart';
import '../../../../../core/theme/design_tokens.dart';
import '../../../../../shared/widgets/error_view.dart';
import '../../../../../shared/widgets/loading_shimmer.dart';

class ReceiptScreen extends ConsumerStatefulWidget {
  final String intentId;
  const ReceiptScreen({super.key, required this.intentId});

  @override
  ConsumerState<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends ConsumerState<ReceiptScreen> {
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
    final receiptAsync = ref.watch(receiptAccessProvider(widget.intentId));

    // React to ReceiptDownloadReady to launch the URL immediately.
    ref.listen(receiptAccessProvider(widget.intentId), (_, next) {
      final state = next.value;
      if (state is ReceiptDownloadReady) {
        _launchDownload(context, state.downloadUrl);
      }
    });

    return Scaffold(
      appBar: AppBar(title: const Text('Receipt')),
      body: receiptAsync.when(
        loading: () => Semantics(
          liveRegion: true,
          label: 'Loading...',
          child: const Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: ShimmerList(itemCount: 6),
          ),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (state) => _buildBody(context, state),
      ),
    );
  }

  Widget _buildBody(BuildContext context, ReceiptAccessState state) {
    if (state is ReceiptAccessLoading) {
      return Semantics(
        liveRegion: true,
        label: 'Loading...',
        child: const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 6),
        ),
      );
    }

    if (state is ReceiptAccessDenied) {
      return Semantics(
        label: 'Access Denied: ${state.reason}',
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_outlined, size: 64, color: Colors.red),
                const SizedBox(height: AppSpacing.md),
                Text('Access Denied', style: AppTypography.label),
                const SizedBox(height: AppSpacing.sm),
                Text(state.reason, style: AppTypography.caption),
              ],
            ),
          ),
        ),
      );
    }

    if (state is ReceiptAccessError) {
      return ErrorView(
        message: state.message,
        onRetry: () =>
            ref.invalidate(receiptAccessProvider(widget.intentId)),
      );
    }

    if (state is ReceiptDownloadRequested || state is ReceiptDownloadReady) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: AppSpacing.md),
            Text('Opening PDF…'),
          ],
        ),
      );
    }

    if (state is ReceiptReady || state is ReceiptDownloadError) {
      final receipt = state is ReceiptReady
          ? state.receipt
          : (state as ReceiptDownloadError).receipt;
      return _ReceiptContent(
        receipt: receipt,
        onDownload: () => ref
            .read(receiptAccessProvider(widget.intentId).notifier)
            .requestDownload(),
        errorMessage:
            state is ReceiptDownloadError ? state.message : null,
      );
    }

    return const SizedBox.shrink();
  }

  Future<void> _launchDownload(BuildContext context, String url) async {
    final launched =
        await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!launched && mounted) {
      await Clipboard.setData(ClipboardData(text: url));
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
              'No PDF viewer installed — download link copied to clipboard'),
        ),
      );
    }
    if (mounted) {
      ref
          .read(receiptAccessProvider(widget.intentId).notifier)
          .onDownloadLaunched();
    }
  }
}

class _ReceiptContent extends StatelessWidget {
  final dynamic receipt;
  final VoidCallback onDownload;
  final String? errorMessage;

  const _ReceiptContent({
    required this.receipt,
    required this.onDownload,
    this.errorMessage,
  });

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      children: [
        _Row('Receipt No', receipt.referenceNumber),
        _Row('Date', DateFormat.yMMMMd().add_jm().format(receipt.issuedAt)),
        if (receipt.payerName != null) _Row('Payer', receipt.payerName!),
        if (receipt.payeeName != null) _Row('Payee', receipt.payeeName!),
        if (receipt.maskedProviderReference != null)
          _Row('Provider Ref', receipt.maskedProviderReference!),
        const Divider(height: AppSpacing.lg),
        Text('Line Items', style: AppTypography.caption),
        const SizedBox(height: AppSpacing.sm),
        ...receipt.lineItems.map<Widget>((item) => Semantics(
              label:
                  '${item.description}: ${item.amountMinorUnits} ${item.currencyCode}',
              child: _Row(
                item.description,
                _formatAmount(item.amountMinorUnits, item.currencyCode),
              ),
            )),
        const Divider(height: AppSpacing.lg),
        _Row(
          'Total',
          _formatAmount(
              receipt.totalAmountMinorUnits, receipt.currencyCode),
        ),
        _Row('Fee',
            _formatAmount(receipt.feeMinorUnits, receipt.currencyCode)),
        if (receipt.refundSummary != null)
          _Row('Refund', receipt.refundSummary!),
        if (errorMessage != null) ...[
          const SizedBox(height: AppSpacing.md),
          Text(
            errorMessage!,
            style: AppTypography.caption.copyWith(color: Colors.red),
          ),
        ],
        const SizedBox(height: AppSpacing.lg),
        SizedBox(
          height: 44,
          child: ElevatedButton.icon(
            icon: const Icon(Icons.download_outlined),
            label: const Text('Download PDF'),
            onPressed: onDownload,
          ),
        ),
      ],
    );
  }

  static String _formatAmount(int minorUnits, String currency) {
    final major = minorUnits / 100.0;
    return '$currency ${major.toStringAsFixed(2)}';
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

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';
import 'package:wallet_agent/modules/transactions/application/receipt_access_notifier.dart';
import 'package:wallet_agent/core/security/screenshot_guard.dart';

class ReceiptScreen extends ConsumerStatefulWidget {
  final String intentId;
  const ReceiptScreen({super.key, required this.intentId});

  @override
  ConsumerState<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends ConsumerState<ReceiptScreen> {
  @override
  void initState() {
    super.initState();
    ScreenshotGuard.enable();
  }

  @override
  void dispose() {
    ScreenshotGuard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final receiptAsync = ref.watch(receiptAccessProvider(widget.intentId));

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
          child: const Center(child: CircularProgressIndicator()),
        ),
        error: (e, _) => Center(child: Text(e.toString())),
        data: (state) => _buildBody(context, state),
      ),
    );
  }

  Widget _buildBody(BuildContext context, ReceiptAccessState state) {
    if (state is ReceiptAccessDenied) {
      return Semantics(
        label: 'Access Denied: ${state.reason}',
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.lock_outlined, size: 64, color: Colors.red),
              const SizedBox(height: 16),
              const Text('Access Denied',
                  style: TextStyle(fontWeight: FontWeight.bold)),
              Text(state.reason),
            ],
          ),
        ),
      );
    }

    if (state is ReceiptDownloadRequested || state is ReceiptDownloadReady) {
      return const Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          CircularProgressIndicator(),
          SizedBox(height: 16),
          Text('Opening PDF…'),
        ]),
      );
    }

    if (state is ReceiptReady || state is ReceiptDownloadError) {
      final receipt = state is ReceiptReady
          ? state.receipt
          : (state as ReceiptDownloadError).receipt;
      return ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _Row('Receipt No', receipt.referenceNumber),
          _Row('Date', DateFormat.yMMMMd().add_jm().format(receipt.issuedAt)),
          if (receipt.maskedProviderReference != null)
            _Row('Provider Ref', receipt.maskedProviderReference!),
          const Divider(),
          ...receipt.lineItems.map<Widget>((item) =>
              _Row(item.description, '${item.currencyCode} ${(item.amountMinorUnits / 100.0).toStringAsFixed(2)}')),
          const Divider(),
          _Row('Total',
              '${receipt.currencyCode} ${(receipt.totalAmountMinorUnits / 100.0).toStringAsFixed(2)}'),
          const SizedBox(height: 16),
          SizedBox(
            height: 44,
            child: ElevatedButton.icon(
              icon: const Icon(Icons.download_outlined),
              label: const Text('Download PDF'),
              onPressed: () => ref
                  .read(receiptAccessProvider(widget.intentId).notifier)
                  .requestDownload(),
            ),
          ),
        ],
      );
    }

    return const SizedBox.shrink();
  }

  Future<void> _launchDownload(BuildContext context, String url) async {
    final launched =
        await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!launched && mounted) {
      await Clipboard.setData(ClipboardData(text: url));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No PDF viewer — link copied')),
      );
    }
    if (mounted) {
      ref
          .read(receiptAccessProvider(widget.intentId).notifier)
          .onDownloadLaunched();
    }
  }
}

class _Row extends StatelessWidget {
  final String label;
  final String value;
  const _Row(this.label, this.value);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label, style: const TextStyle(color: Colors.grey)),
            Flexible(
                child: Text(value, textAlign: TextAlign.end)),
          ],
        ),
      );
}

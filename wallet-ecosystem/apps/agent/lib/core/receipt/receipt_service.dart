import 'dart:io';

import 'package:intl/intl.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:share_plus/share_plus.dart';

class ReceiptData {
  final String referenceNumber;
  final String transactionType;
  final String customerName;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final int netMinorUnits;
  final String currencyCode;
  final String agentName;
  final String agentLocationCode;
  final DateTime timestamp;

  const ReceiptData({
    required this.referenceNumber,
    // Allow callers to use either `type` or `transactionType`
    String? type,
    String? transactionType,
    required this.customerName,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.netMinorUnits,
    required this.currencyCode,
    this.agentName = '',
    this.agentLocationCode = '',
    required this.timestamp,
  }) : transactionType = type ?? transactionType ?? '';
}

class ReceiptService {
  Future<File> generateReceipt(ReceiptData data) async {
    final pdf = pw.Document();
    final fmt = NumberFormat.currency(
      symbol: '${data.currencyCode} ',
      decimalDigits: 2,
    );

    String format(int minorUnits) => fmt.format(minorUnits / 100);

    pdf.addPage(
      pw.Page(
        pageFormat: PdfPageFormat.a6,
        build: (ctx) => pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            pw.Center(
              child: pw.Text(
                'OleKard Agent Receipt',
                style: const pw.TextStyle(
                  fontSize: 16,
                  fontWeight: pw.FontWeight.bold,
                ),
              ),
            ),
            pw.SizedBox(height: 12),
            _row('Transaction Type', data.transactionType),
            _row('Reference', data.referenceNumber),
            _row('Date',
                DateFormat('dd MMM yyyy HH:mm').format(data.timestamp.toLocal())),
            pw.Divider(),
            _row('Customer', data.customerName),
            _row('Amount', format(data.amountMinorUnits)),
            _row('Fee', format(data.feeMinorUnits)),
            _row('Net', format(data.netMinorUnits)),
            pw.Divider(),
            if (data.agentName.isNotEmpty) _row('Agent', data.agentName),
            if (data.agentLocationCode.isNotEmpty)
              _row('Location', data.agentLocationCode),
            pw.SizedBox(height: 12),
            pw.Center(
              child: pw.Text(
                'Thank you for using OleKard',
                style: const pw.TextStyle(fontSize: 10),
              ),
            ),
          ],
        ),
      ),
    );

    final dir = await getTemporaryDirectory();
    final file = File(
      '${dir.path}/receipt_${data.referenceNumber.replaceAll(RegExp(r'[^A-Za-z0-9]'), '_')}.pdf',
    );
    await file.writeAsBytes(await pdf.save());
    return file;
  }

  Future<void> shareReceiptInstance(ReceiptData data) async {
    final file = await generateReceipt(data);
    await Share.shareXFiles(
      [XFile(file.path, mimeType: 'application/pdf')],
      subject: 'Receipt ${data.referenceNumber}',
    );
  }

  /// Static convenience wrapper — creates a temporary [ReceiptService] instance.
  static Future<void> shareReceipt(ReceiptData data) =>
      ReceiptService().shareReceiptInstance(data);

  pw.Widget _row(String label, String value) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 2),
      child: pw.Row(
        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
        children: [
          pw.Text(label, style: const pw.TextStyle(fontSize: 10)),
          pw.Text(
            value,
            style: const pw.TextStyle(
              fontSize: 10,
              fontWeight: pw.FontWeight.bold,
            ),
          ),
        ],
      ),
    );
  }
}

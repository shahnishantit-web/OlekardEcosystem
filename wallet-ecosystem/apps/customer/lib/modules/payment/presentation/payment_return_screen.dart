import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_payment/shared_payment.dart';

class PaymentReturnScreen extends ConsumerStatefulWidget {
  const PaymentReturnScreen({
    super.key,
    required this.intentId,
    required this.purposeType,
    required this.purposeReference,
  });

  final String intentId;
  final String purposeType;
  final String purposeReference;

  @override
  ConsumerState<PaymentReturnScreen> createState() =>
      _PaymentReturnScreenState();
}

class _PaymentReturnScreenState extends ConsumerState<PaymentReturnScreen>
    with WidgetsBindingObserver {
  Timer? _timer;
  bool _refreshing = false;
  String? _paymentStatus;
  String? _fulfilmentStatus;
  String? _error;

  bool get _isComplete =>
      _fulfilmentStatus == 'issued' ||
      _fulfilmentStatus == 'fulfilled' ||
      _fulfilmentStatus == 'confirmed';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_refresh());
    _timer = Timer.periodic(const Duration(seconds: 4), (_) {
      if (!_refreshing && !_isComplete && mounted) unawaited(_refresh());
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_refresh());
  }

  Map<String, dynamic> _data(Response<Object?> response) {
    final body = response.data;
    if (body is! Map) return <String, dynamic>{};
    final value = body['data'];
    return value is Map
        ? Map<String, dynamic>.from(value)
        : <String, dynamic>{};
  }

  Future<void> _refresh() async {
    if (_refreshing || widget.intentId.isEmpty) return;
    _refreshing = true;
    try {
      final dio = ref.read(httpClientProvider);
      final status = _data(await dio.get<Object?>(
        '/api/v1/payment/intents/${widget.intentId}/status',
      ));
      final paymentStatus = status['status']?.toString().toLowerCase();
      String? fulfilmentStatus;

      if (widget.purposeType == 'paid_event_ticket' &&
          widget.purposeReference.isNotEmpty) {
        final order = _data(await dio.get<Object?>(
          '/v1/events/orders/${widget.purposeReference}',
        ));
        fulfilmentStatus =
            order['fulfilment_state']?.toString().toLowerCase() ??
                order['order_state']?.toString().toLowerCase();
      } else if (widget.purposeType == 'funeral_contribution') {
        final response = await dio.get<Object?>(
          '/api/v1/community/funeral/my/obligations',
        );
        final body = response.data;
        final rows = body is Map ? body['data'] : null;
        if (rows is List) {
          final obligation = rows
              .whereType<Map>()
              .cast<Map<String, dynamic>>()
              .where(
                (row) => row['id']?.toString() == widget.purposeReference,
              )
              .firstOrNull;
          fulfilmentStatus =
              obligation?['fulfilment_status']?.toString().toLowerCase() ??
                  obligation?['status']?.toString().toLowerCase();
        }
      }

      if (mounted) {
        setState(() {
          _paymentStatus = paymentStatus;
          _fulfilmentStatus = fulfilmentStatus;
          _error = null;
        });
        if (_isComplete) _timer?.cancel();
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() => _error = error.response?.statusCode == 401
            ? 'Your session needs attention. Sign in again to refresh this payment.'
            : 'Payment status is taking longer to load. Keep this screen open or refresh.');
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error =
            'Payment status is taking longer to load. Keep this screen open or refresh.');
      }
    } finally {
      _refreshing = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final isPaid = _paymentStatus == 'succeeded';
    final title = _isComplete
        ? 'Payment complete'
        : isPaid
            ? 'Payment received'
            : 'Checking your payment';
    final message = _isComplete
        ? 'Your purchase is ready in the app.'
        : isPaid
            ? 'Payment is confirmed. We are waiting for the service to finish recording it.'
            : 'The bank response was received. OleKard is checking the latest status.';

    return Scaffold(
      appBar: AppBar(title: const Text('Payment status')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.all(24),
            children: [
              Icon(
                _isComplete ? Icons.check_circle : Icons.sync,
                size: 56,
                color: _isComplete ? colors.primary : colors.tertiary,
              ),
              const SizedBox(height: 16),
              Text(title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: 20),
              if (_paymentStatus != null)
                ListTile(
                  leading: const Icon(Icons.account_balance_wallet_outlined),
                  title: const Text('Payment'),
                  trailing: Text(_paymentStatus!.replaceAll('_', ' ')),
                ),
              if (_fulfilmentStatus != null)
                ListTile(
                  leading: const Icon(Icons.confirmation_number_outlined),
                  title: Text(widget.purposeType == 'paid_event_ticket'
                      ? 'Tickets'
                      : 'Contribution'),
                  trailing: Text(_fulfilmentStatus!.replaceAll('_', ' ')),
                ),
              if (_refreshing && !_isComplete)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: LinearProgressIndicator(),
                ),
              if (_error != null) ...[
                Text(_error!,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: colors.error)),
                const SizedBox(height: 12),
              ],
              if (widget.purposeType == 'paid_event_ticket' && _isComplete)
                FilledButton.icon(
                  onPressed: () => context.go('/events/tickets'),
                  icon: const Icon(Icons.confirmation_number_outlined),
                  label: const Text('View my tickets'),
                )
              else if (widget.purposeType == 'funeral_contribution' &&
                  _isComplete)
                FilledButton.icon(
                  onPressed: () => context.go('/services/funeral'),
                  icon: const Icon(Icons.volunteer_activism_outlined),
                  label: const Text('View Funeral Assistance'),
                )
              else
                OutlinedButton.icon(
                  onPressed: _refreshing ? null : _refresh,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Refresh status'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

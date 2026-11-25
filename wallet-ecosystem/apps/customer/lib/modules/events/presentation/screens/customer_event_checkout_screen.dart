import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:uuid/uuid.dart';

class EventCheckoutScreen extends StatefulWidget {
  const EventCheckoutScreen({
    super.key,
    required this.event,
    required this.category,
    required this.dio,
  });

  final Map<String, dynamic> event;
  final Map<String, dynamic> category;
  final Dio dio;

  @override
  State<EventCheckoutScreen> createState() => _EventCheckoutScreenState();
}

class _EventCheckoutScreenState extends State<EventCheckoutScreen>
    with WidgetsBindingObserver {
  int _quantity = 1;
  bool _busy = false;
  bool _refreshing = false;
  Timer? _statusTimer;
  String? _error;
  String? _selectedOptionId;
  String? _paymentIntentId;
  String? _orderId;
  Map<String, dynamic>? _order;
  Map<String, dynamic>? _attempt;
  Map<String, dynamic>? _paymentStatus;
  List<Map<String, dynamic>> _paymentOptions = [];

  final _holdKey = const Uuid().v4();
  final _quoteKey = const Uuid().v4();
  final _orderKey = const Uuid().v4();
  final _intentKey = const Uuid().v4();
  String _attemptKey = const Uuid().v4();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _statusTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _attempt != null) {
      unawaited(_refreshOrder());
    }
  }

  bool get _isCompleted =>
      _order?['fulfilment_state'] == 'issued' ||
      _order?['order_state'] == 'issued';

  bool get _isPaid => _order?['payment_state'] == 'confirmed';

  bool get _isPaymentAccepted => _paymentStatus?['status'] == 'succeeded';

  bool get _isFailedAttempt {
    final status = _attempt?['status']?.toString().toLowerCase();
    return status != null &&
        const {'failed', 'cancelled', 'canceled', 'declined', 'expired'}
            .contains(status);
  }

  void _startStatusPolling() {
    _statusTimer?.cancel();
    _statusTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!_refreshing && !_isCompleted && mounted) {
        unawaited(_refreshOrder());
      }
    });
  }

  Map<String, dynamic> _responseData(Response<Object?> response) {
    final body = response.data as Map<String, dynamic>;
    return body['data'] as Map<String, dynamic>;
  }

  String? _errorMessage(DioException error) {
    final body = error.response?.data;
    if (body is Map) {
      final message = body['message'] ?? body['error'];
      if (message is String && message.isNotEmpty) return message;
      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first.toString();
      }
    }
    return null;
  }

  Future<void> _prepareCheckout() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final eventId = widget.event['id'].toString();
      final hold = _responseData(await widget.dio.post<Object?>(
        '/v1/events/$eventId/inventory-holds',
        data: {
          'category_id': widget.category['id'],
          'quantity': _quantity,
        },
        options: Options(headers: {'Idempotency-Key': _holdKey}),
      ));
      final quote = _responseData(await widget.dio.post<Object?>(
        '/v1/events/$eventId/quotes',
        data: {
          'hold_ids': [hold['id']],
        },
        options: Options(headers: {'Idempotency-Key': _quoteKey}),
      ));
      final order = _responseData(await widget.dio.post<Object?>(
        '/v1/events/$eventId/orders',
        data: {'quote_id': quote['quote_id'], 'customer_confirmed': true},
        options: Options(headers: {'Idempotency-Key': _orderKey}),
      ));
      final orderId = order['order_id'].toString();
      final intent = _responseData(await widget.dio.post<Object?>(
        '/v1/events/orders/$orderId/payment-intent',
        data: const <String, dynamic>{},
        options: Options(headers: {'Idempotency-Key': _intentKey}),
      ));
      final options =
          (intent['available_payment_options'] as List<dynamic>? ?? const [])
              .whereType<Map<String, dynamic>>()
              .toList();
      if (!mounted) return;
      setState(() {
        _order = order;
        _orderId = orderId;
        _paymentIntentId = intent['payment_intent_id']?.toString();
        _paymentOptions = options;
        final defaultOption =
            options.where((option) => option['is_default'] == true).firstOrNull;
        _selectedOptionId = defaultOption?['provider_account_id']?.toString() ??
            options.firstOrNull?['provider_account_id']?.toString();
        if (options.isEmpty) {
          _error =
              'No bank or card payment method is available for this event yet.';
        }
      });
    } on DioException catch (error) {
      if (mounted) {
        setState(() => _error = _errorMessage(error) ??
            'Checkout could not be prepared. Please try again.');
      }
    } catch (_) {
      if (mounted)
        setState(
            () => _error = 'Checkout could not be prepared. Please try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _startPayment() async {
    final intentId = _paymentIntentId;
    final option = _paymentOptions
        .where((candidate) =>
            candidate['provider_account_id']?.toString() == _selectedOptionId)
        .firstOrNull;
    final providerCode = option?['provider_code']?.toString();
    final providerAccountId = option?['provider_account_id']?.toString();
    if (intentId == null || providerCode == null || providerAccountId == null)
      return;
    if (_isFailedAttempt) _attemptKey = const Uuid().v4();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final attempt = _responseData(await widget.dio.post<Object?>(
        '/api/v1/payment/intents/$intentId/attempts',
        data: {
          'provider_code': providerCode,
          'provider_account_id': providerAccountId,
        },
        options: Options(headers: {'Idempotency-Key': _attemptKey}),
      ));
      if (!mounted) return;
      setState(() => _attempt = attempt);
      _startStatusPolling();
      final actionData = attempt['next_action_data'];
      final actionUrl = actionData is Map
          ? actionData['url']?.toString() ??
              actionData['redirect_url']?.toString()
          : null;
      if (actionUrl != null) {
        final uri = Uri.tryParse(actionUrl);
        if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) {
          final checkoutUri = !kIsWeb && uri.path == '/mock-bank/checkout'
              ? uri.replace(queryParameters: {
                  ...uri.queryParameters,
                  'return_to_app': '1',
                })
              : uri;
          await launchUrl(
            checkoutUri,
            mode: !kIsWeb && checkoutUri.queryParameters['return_to_app'] == '1'
                ? LaunchMode.inAppBrowserView
                : LaunchMode.platformDefault,
          );
        }
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() =>
            _error = _errorMessage(error) ?? 'Payment could not be started.');
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'Payment could not be started.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refreshOrder() async {
    final orderId = _orderId;
    if (orderId == null || _refreshing) return;
    final intentId = _paymentIntentId;
    _refreshing = true;
    if (mounted) setState(() => _error = null);
    try {
      if (intentId != null) {
        try {
          final status = _responseData(await widget.dio.get<Object?>(
            '/api/v1/payment/intents/$intentId/status',
          ));
          if (mounted) setState(() => _paymentStatus = status);
          final latestAttempt = status['latest_attempt'];
          if (latestAttempt is Map<String, dynamic> && mounted) {
            setState(() => _attempt = {...?_attempt, ...latestAttempt});
          }
        } on DioException {
          // The event order remains the source of truth if intent status is unavailable.
        }
      }
      final order = _responseData(await widget.dio.get<Object?>(
        '/v1/events/orders/$orderId',
      ));
      if (mounted) setState(() => _order = order);
      if (_isCompleted) _statusTimer?.cancel();
    } on DioException catch (error) {
      if (mounted) {
        setState(() =>
            _error = _errorMessage(error) ?? 'Could not refresh order status.');
      }
    } finally {
      _refreshing = false;
    }
  }

  Widget _paymentStatusCard(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    final IconData icon;
    final Color color;
    final String title;
    final String message;
    if (_isCompleted) {
      icon = Icons.check_circle;
      color = colors.primary;
      title = 'Payment complete';
      message = 'Your tickets are ready. View them in My tickets.';
    } else if (_isPaid) {
      icon = Icons.hourglass_top;
      color = colors.primary;
      title = 'Payment received';
      message = 'Your payment is confirmed. Your tickets are being prepared.';
    } else if (_isPaymentAccepted) {
      icon = Icons.hourglass_top;
      color = colors.tertiary;
      title = 'Payment received';
      message =
          'Final confirmation is still processing. Do not pay again; this screen will update when your tickets are ready.';
    } else if (_isFailedAttempt) {
      icon = Icons.error_outline;
      color = colors.error;
      title = 'Payment not completed';
      message =
          'The provider did not complete this payment. You can try again.';
    } else {
      icon = Icons.hourglass_top;
      color = colors.tertiary;
      title = 'Waiting for payment confirmation';
      message = 'This screen checks for updates automatically.';
    }
    return Card(
      color: color.withOpacity(0.08),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: color),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            color: color,
                            fontWeight: FontWeight.w700,
                          )),
                  const SizedBox(height: 4),
                  Text(message),
                  if (_attempt?['status'] is String) ...[
                    const SizedBox(height: 4),
                    Text('Provider status: ${_attempt!['status']}'),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    final category = widget.category;
    final precision =
        ((event['currency_precision'] as num?)?.toInt() ?? 2).clamp(0, 4);
    final perPerson = (category['max_per_person'] as num?)?.toInt() ?? 10;
    final eventOrderLimit =
        (event['max_tickets_per_order'] as num?)?.toInt() ?? 10;
    final maxQuantity =
        (perPerson < eventOrderLimit ? perPerson : eventOrderLimit)
            .clamp(1, 50);
    final unitPrice = (category['price_minor'] as num).toInt();
    final totalMinor =
        (_order?['total_minor'] as num?)?.toInt() ?? unitPrice * _quantity;
    final currency = _order?['currency_code']?.toString() ??
        event['currency_code']?.toString() ??
        'BWP';

    return Scaffold(
      appBar: AppBar(title: const Text('Ticket checkout')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(event['title']?.toString() ?? 'Event',
              style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 4),
          Text('${category['name']}  •  ${event['organiser_name'] ?? ''}'),
          if (_order == null) ...[
            const SizedBox(height: 24),
            Text('Quantity', style: Theme.of(context).textTheme.titleMedium),
            Row(children: [
              IconButton(
                onPressed: _busy || _quantity <= 1
                    ? null
                    : () => setState(() => _quantity--),
                icon: const Icon(Icons.remove_circle_outline),
              ),
              Text('$_quantity'),
              IconButton(
                onPressed: _busy || _quantity >= maxQuantity
                    ? null
                    : () => setState(() => _quantity++),
                icon: const Icon(Icons.add_circle_outline),
              ),
              const Spacer(),
              Text(_formatMoney(unitPrice * _quantity, currency, precision)),
            ]),
            const SizedBox(height: 18),
            FilledButton(
              onPressed: _busy ? null : _prepareCheckout,
              child: Text(_busy ? 'Preparing checkout…' : 'Continue'),
            ),
          ] else ...[
            const SizedBox(height: 20),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Order total'),
              trailing: Text(
                _formatMoney(totalMinor, currency, precision),
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (_attempt != null || _isPaid) _paymentStatusCard(context),
            const SizedBox(height: 12),
            if (!_isCompleted && !_isPaid && !_isPaymentAccepted) ...[
              Text('Choose how to pay',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              ..._paymentOptions.map((option) {
                final accountId =
                    option['provider_account_id']?.toString() ?? '';
                return Card(
                  child: RadioListTile<String>(
                    value: accountId,
                    groupValue: _selectedOptionId,
                    onChanged: _busy
                        ? null
                        : (value) => setState(() {
                              _selectedOptionId = value;
                              _attemptKey = const Uuid().v4();
                              _attempt = null;
                            }),
                    secondary: Icon(_paymentIcon(option)),
                    title: Text(_paymentLabel(option)),
                    subtitle: option['display_description'] is String
                        ? Text(option['display_description'] as String)
                        : null,
                  ),
                );
              }),
              const SizedBox(height: 12),
              FilledButton(
                onPressed:
                    _busy || _selectedOptionId == null ? null : _startPayment,
                child: Text(_busy ? 'Starting payment…' : 'Pay securely'),
              ),
            ],
            if (_isCompleted)
              FilledButton.icon(
                onPressed: () => context.go('/events/tickets'),
                icon: const Icon(Icons.confirmation_number_outlined),
                label: const Text('View my tickets'),
              ),
            if ((_attempt != null || _isPaid || _isPaymentAccepted) &&
                !_isCompleted)
              OutlinedButton.icon(
                onPressed: _refreshing ? null : _refreshOrder,
                icon: const Icon(Icons.refresh),
                label: Text(_refreshing ? 'Refreshing…' : 'Refresh status'),
              ),
          ],
          if (_busy) ...[
            const SizedBox(height: 12),
            const LinearProgressIndicator(),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
        ],
      ),
    );
  }
}

IconData _paymentIcon(Map<String, dynamic> option) {
  final method = option['payment_method']?.toString().toLowerCase() ?? '';
  final instruments =
      (option['eligible_instrument_types'] as List<dynamic>? ?? const [])
          .map((value) => value.toString().toLowerCase());
  if (method.contains('card') ||
      instruments.any((value) => value.contains('card'))) {
    return Icons.credit_card;
  }
  if (method.contains('bank') ||
      instruments.any((value) => value.contains('bank'))) {
    return Icons.account_balance;
  }
  return Icons.payments_outlined;
}

String _paymentLabel(Map<String, dynamic> option) {
  final label = option['display_label']?.toString() ??
      option['display_name']?.toString() ??
      'Payment provider';
  final method = option['payment_method']?.toString().toLowerCase() ?? '';
  final instruments =
      (option['eligible_instrument_types'] as List<dynamic>? ?? const [])
          .map((value) => value.toString().toLowerCase());
  if (method.contains('card') ||
      instruments.any((value) => value.contains('card'))) {
    return 'Card · $label';
  }
  if (method.contains('bank') ||
      instruments.any((value) => value.contains('bank'))) {
    return 'Bank transfer · $label';
  }
  return label;
}

String _formatMoney(int minor, String currencyCode, int precision) {
  var divisor = 1;
  for (var index = 0; index < precision; index++) {
    divisor *= 10;
  }
  return '$currencyCode ${(minor / divisor).toStringAsFixed(precision)}';
}

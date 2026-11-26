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
    required this.selectedQuantities,
    required this.dio,
    required this.session,
  });

  final Map<String, dynamic> event;
  final Map<String, int> selectedQuantities;
  final Dio dio;
  final EventCheckoutSession session;

  static Future<void> showPaymentSheet({
    required BuildContext context,
    required Map<String, dynamic> event,
    required Map<String, int> selectedQuantities,
    required Dio dio,
    required EventCheckoutSession session,
  }) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: false,
        backgroundColor: Colors.transparent,
        builder: (_) => FractionallySizedBox(
          heightFactor: .94,
          child: EventCheckoutScreen(
            event: event,
            selectedQuantities: selectedQuantities,
            dio: dio,
            session: session,
          ),
        ),
      );

  @override
  State<EventCheckoutScreen> createState() => _EventCheckoutScreenState();
}

class EventCheckoutSession {
  EventCheckoutSession({required Map<String, int> selectedQuantities})
      : selectedQuantities = Map.unmodifiable(selectedQuantities);

  final Map<String, int> selectedQuantities;
  Map<String, dynamic>? snapshot;
}

class _EventCheckoutScreenState extends State<EventCheckoutScreen>
    with WidgetsBindingObserver {
  bool _busy = false;
  bool _refreshing = false;
  Timer? _statusTimer;
  String? _error;
  String? _selectedOptionId;
  bool _confirmedUpdatedPrice = false;
  String? _paymentIntentId;
  String? _orderId;
  Map<String, dynamic>? _order;
  Map<String, dynamic>? _attempt;
  Map<String, dynamic>? _paymentStatus;
  List<Map<String, dynamic>> _paymentOptions = [];

  late final Map<String, String> _holdKeys;
  late String _quoteKey;
  late String _orderKey;
  late String _intentKey;
  String _attemptKey = const Uuid().v4();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final snapshot = widget.session.snapshot;
    _holdKeys = snapshot?['hold_keys'] is Map
        ? (snapshot!['hold_keys'] as Map)
            .map((key, value) => MapEntry(key.toString(), value.toString()))
        : {
            for (final categoryId in widget.selectedQuantities.keys)
              categoryId: const Uuid().v4(),
          };
    _quoteKey = snapshot?['quote_key']?.toString() ?? const Uuid().v4();
    _orderKey = snapshot?['order_key']?.toString() ?? const Uuid().v4();
    _intentKey = snapshot?['intent_key']?.toString() ?? const Uuid().v4();
    _attemptKey = snapshot?['attempt_key']?.toString() ?? const Uuid().v4();
    _orderId = snapshot?['order_id']?.toString();
    _paymentIntentId = snapshot?['payment_intent_id']?.toString();
    _order = snapshot?['order'] is Map
        ? Map<String, dynamic>.from(snapshot!['order'] as Map)
        : null;
    _attempt = snapshot?['attempt'] is Map
        ? Map<String, dynamic>.from(snapshot!['attempt'] as Map)
        : null;
    _paymentStatus = snapshot?['payment_status'] is Map
        ? Map<String, dynamic>.from(snapshot!['payment_status'] as Map)
        : null;
    _paymentOptions =
        (snapshot?['payment_options'] as List<dynamic>? ?? const [])
            .whereType<Map>()
            .map(Map<String, dynamic>.from)
            .toList();
    _selectedOptionId = snapshot?['selected_option_id']?.toString();
    _confirmedUpdatedPrice = snapshot?['confirmed_updated_price'] == true;
    if (_order == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _prepareCheckout());
    } else if (_attempt != null && !_isCompleted) {
      _startStatusPolling();
      unawaited(_refreshOrder());
    }
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

  void _persistSession() {
    widget.session.snapshot = {
      'hold_keys': _holdKeys,
      'quote_key': _quoteKey,
      'order_key': _orderKey,
      'intent_key': _intentKey,
      'attempt_key': _attemptKey,
      'order_id': _orderId,
      'payment_intent_id': _paymentIntentId,
      'order': _order,
      'attempt': _attempt,
      'payment_status': _paymentStatus,
      'payment_options': _paymentOptions,
      'selected_option_id': _selectedOptionId,
      'confirmed_updated_price': _confirmedUpdatedPrice,
    };
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
      final holdIds = <String>[];
      for (final selection in widget.selectedQuantities.entries) {
        final hold = _responseData(await widget.dio.post<Object?>(
          '/v1/events/$eventId/inventory-holds',
          data: {'category_id': selection.key, 'quantity': selection.value},
          options:
              Options(headers: {'Idempotency-Key': _holdKeys[selection.key]}),
        ));
        holdIds.add(hold['id'].toString());
      }
      final quote = _responseData(await widget.dio.post<Object?>(
        '/v1/events/$eventId/quotes',
        data: {'hold_ids': holdIds},
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
        _confirmedUpdatedPrice = false;
        final defaultOption =
            options.where((option) => option['is_default'] == true).firstOrNull;
        _selectedOptionId = defaultOption?['provider_account_id']?.toString() ??
            options.firstOrNull?['provider_account_id']?.toString();
        if (options.isEmpty) {
          _error =
              'No bank or card payment method is available for this event yet.';
        }
      });
      _persistSession();
    } on DioException catch (error) {
      if (mounted) {
        setState(() => _error = _errorMessage(error) ??
            'Checkout could not be prepared. Please try again.');
        _persistSession();
      }
    } catch (_) {
      if (mounted) {
        setState(
            () => _error = 'Checkout could not be prepared. Please try again.');
        _persistSession();
      }
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
      _persistSession();
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
        _persistSession();
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Payment could not be started.');
        _persistSession();
      }
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
      if (mounted) _persistSession();
      if (_isCompleted) _statusTimer?.cancel();
    } on DioException catch (error) {
      if (mounted) {
        setState(() =>
            _error = _errorMessage(error) ?? 'Could not refresh order status.');
        _persistSession();
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
    final pricing = _order?['pricing'] is Map
        ? Map<String, dynamic>.from(_order!['pricing'] as Map)
        : const <String, dynamic>{};
    final precision = ((pricing['currency_precision'] as num?)?.toInt() ??
            (event['currency_precision'] as num?)?.toInt() ??
            2)
        .clamp(0, 4);
    final currency = _order?['currency_code']?.toString() ??
        pricing['currency_code']?.toString() ??
        event['currency_code']?.toString() ??
        'BWP';
    final lines = (_order?['line_snapshot'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList();
    final eventCategories = (event['categories'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .map(Map<String, dynamic>.from)
        .toList();
    final priceChanged = lines.any((line) {
      final category = eventCategories
          .where((item) =>
              item['id']?.toString() == line['category_id']?.toString())
          .firstOrNull;
      final currentPrice = category?['price_minor'];
      final quotedPrice = line['unit_price_minor'];
      return currentPrice is num &&
          quotedPrice is num &&
          currentPrice.toInt() != quotedPrice.toInt();
    });
    final policies = <(String, String)>[
      if (event['entry_policy'] is String &&
          (event['entry_policy'] as String).trim().isNotEmpty)
        ('Entry', event['entry_policy'] as String),
      if (event['cancellation_policy'] is String &&
          (event['cancellation_policy'] as String).trim().isNotEmpty)
        ('Cancellation', event['cancellation_policy'] as String),
      if (event['transfer_policy'] is String &&
          (event['transfer_policy'] as String).trim().isNotEmpty)
        ('Transfer', event['transfer_policy'] as String),
    ];

    Widget priceLine(String label, int minor, {bool emphasize = false}) {
      final style = emphasize
          ? Theme.of(context).textTheme.titleMedium
          : Theme.of(context).textTheme.bodyMedium;
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Expanded(child: Text(label, style: style)),
          Text(_formatMoney(minor, currency, precision), style: style),
        ]),
      );
    }

    final totalMinor = (_order?['total_minor'] as num?)?.toInt() ??
        (pricing['total_minor'] as num?)?.toInt() ??
        0;
    return PopScope(
      canPop: !_busy,
      child: Material(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
        clipBehavior: Clip.antiAlias,
        child: SafeArea(
          top: false,
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 14, 12, 8),
              child: Row(children: [
                Expanded(
                    child: Text('Payment',
                        style: Theme.of(context).textTheme.titleLarge)),
                IconButton(
                  tooltip: 'Close payment',
                  onPressed: _busy ? null : () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ]),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
                children: [
                  Text(event['title']?.toString() ?? 'Event',
                      style: Theme.of(context).textTheme.titleMedium),
                  if ((event['organiser_name']?.toString() ?? '').isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 3),
                      child: Text(event['organiser_name'].toString(),
                          style: Theme.of(context).textTheme.bodySmall),
                    ),
                  const SizedBox(height: 18),
                  if (_order == null) ...[
                    if (_busy) ...[
                      const LinearProgressIndicator(),
                      const SizedBox(height: 12),
                      const Text(
                          'Checking availability and preparing your order...'),
                    ],
                    if (_error != null) ...[
                      Text(_error!,
                          style: TextStyle(
                              color: Theme.of(context).colorScheme.error)),
                      OutlinedButton.icon(
                        onPressed: _busy ? null : _prepareCheckout,
                        icon: const Icon(Icons.refresh),
                        label: const Text('Try again'),
                      ),
                    ],
                  ] else ...[
                    Text('Your tickets',
                        style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 8),
                    ...lines.map((line) => ListTile(
                          contentPadding: EdgeInsets.zero,
                          title: Text(line['category_name'].toString() +
                              ' × ' +
                              line['quantity'].toString()),
                          subtitle: Text(_formatMoney(
                                  (line['unit_price_minor'] as num? ?? 0)
                                      .toInt(),
                                  currency,
                                  precision) +
                              ' each'),
                          trailing: Text(_formatMoney(
                              (line['subtotal_minor'] as num? ?? 0).toInt(),
                              currency,
                              precision)),
                        )),
                    const Divider(height: 20),
                    priceLine('Subtotal',
                        (pricing['subtotal_minor'] as num? ?? 0).toInt()),
                    priceLine(
                        'Fees', (pricing['fees_minor'] as num? ?? 0).toInt()),
                    priceLine(
                        'Taxes', (pricing['taxes_minor'] as num? ?? 0).toInt()),
                    const Divider(height: 20),
                    priceLine('Total', totalMinor, emphasize: true),
                    if (priceChanged) ...[
                      const SizedBox(height: 12),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                            color: Theme.of(context)
                                .colorScheme
                                .secondaryContainer,
                            borderRadius: BorderRadius.circular(12)),
                        child: CheckboxListTile(
                          contentPadding: EdgeInsets.zero,
                          value: _confirmedUpdatedPrice,
                          onChanged: _busy
                              ? null
                              : (value) => setState(() {
                                    _confirmedUpdatedPrice = value ?? false;
                                    _persistSession();
                                  }),
                          title: const Text(
                              'I reviewed the updated ticket price.'),
                          controlAffinity: ListTileControlAffinity.leading,
                        ),
                      ),
                    ],
                    if (policies.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      Text('Event terms',
                          style: Theme.of(context).textTheme.titleSmall),
                      const SizedBox(height: 4),
                      for (final policy in policies)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Text(policy.$1 + ': ' + policy.$2),
                        ),
                    ],
                    if (!_isCompleted && !_isPaid && !_isPaymentAccepted) ...[
                      const SizedBox(height: 18),
                      Text('Payment method',
                          style: Theme.of(context).textTheme.titleMedium),
                      const SizedBox(height: 6),
                      if (_paymentOptions.isEmpty)
                        Text(
                            _error ??
                                'No payment methods are available for this order.',
                            style: TextStyle(
                                color: Theme.of(context).colorScheme.error)),
                      ..._paymentOptions.map((option) {
                        final accountId =
                            option['provider_account_id']?.toString() ?? '';
                        return Card(
                          margin: const EdgeInsets.only(bottom: 8),
                          child: RadioListTile<String>(
                            value: accountId,
                            groupValue: _selectedOptionId,
                            onChanged: _busy
                                ? null
                                : (value) => setState(() {
                                      _selectedOptionId = value;
                                      _attemptKey = const Uuid().v4();
                                      _attempt = null;
                                      _persistSession();
                                    }),
                            secondary: Icon(_paymentIcon(option)),
                            title: Text(_paymentLabel(option)),
                            subtitle: option['display_description'] is String
                                ? Text(option['display_description'] as String)
                                : null,
                          ),
                        );
                      }),
                    ],
                    if (_attempt != null || _isPaid || _isPaymentAccepted) ...[
                      const SizedBox(height: 12),
                      _paymentStatusCard(context),
                    ],
                    if ((_attempt != null || _isPaid || _isPaymentAccepted) &&
                        !_isCompleted)
                      TextButton.icon(
                        onPressed: _refreshing ? null : _refreshOrder,
                        icon: const Icon(Icons.refresh),
                        label: Text(
                            _refreshing ? 'Refreshing...' : 'Refresh status'),
                      ),
                  ],
                  if (_error != null && _order != null) ...[
                    const SizedBox(height: 10),
                    Text(_error!,
                        style: TextStyle(
                            color: Theme.of(context).colorScheme.error)),
                  ],
                  if (_busy && _order != null) ...[
                    const SizedBox(height: 8),
                    const LinearProgressIndicator(),
                  ],
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(20, 10, 20, 8),
              decoration: const BoxDecoration(
                  border: Border(top: BorderSide(color: Colors.black12))),
              child: SafeArea(
                top: false,
                child: Row(children: [
                  Expanded(
                      child: OutlinedButton(
                    onPressed: _busy ? null : () => Navigator.of(context).pop(),
                    child: const Text('Cancel'),
                  )),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      onPressed: _busy
                          ? null
                          : _isCompleted
                              ? () {
                                  Navigator.of(context).pop();
                                  context.go('/events/tickets');
                                }
                              : _order == null
                                  ? null
                                  : _isPaid || _isPaymentAccepted
                                      ? _refreshing
                                          ? null
                                          : _refreshOrder
                                      : _paymentOptions.isEmpty ||
                                              _selectedOptionId == null ||
                                              (priceChanged &&
                                                  !_confirmedUpdatedPrice)
                                          ? null
                                          : _startPayment,
                      child: Text(_busy
                          ? 'Processing...'
                          : _isCompleted
                              ? 'View my tickets'
                              : _isPaid || _isPaymentAccepted
                                  ? 'Refresh status'
                                  : _isFailedAttempt
                                      ? 'Retry payment'
                                      : 'Pay now'),
                    ),
                  ),
                ]),
              ),
            ),
          ]),
        ),
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

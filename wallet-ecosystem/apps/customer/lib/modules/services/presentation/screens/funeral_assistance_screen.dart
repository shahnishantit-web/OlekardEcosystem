import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:uuid/uuid.dart';
import '../../../../core/di/providers.dart';
import '../../../../core/theme/ok_theme_extension.dart';

class FuneralObligationItem {
  final String id;
  final String collectionId;
  final String organisationId;
  final String organisationName;
  final String communityName;
  final String collectionPurpose;
  final int amountMinor;
  final String currencyCode;
  final String status;
  final String fulfilmentStatus;
  final String collectionStatus;

  const FuneralObligationItem({
    required this.id,
    required this.collectionId,
    required this.organisationId,
    required this.organisationName,
    required this.communityName,
    required this.collectionPurpose,
    required this.amountMinor,
    required this.currencyCode,
    required this.status,
    required this.fulfilmentStatus,
    required this.collectionStatus,
  });

  factory FuneralObligationItem.fromJson(Map<String, dynamic> json) =>
      FuneralObligationItem(
        id: json['id'] as String? ?? '',
        collectionId: json['collection_id'] as String? ?? '',
        organisationId: json['organisation_id'] as String? ?? '',
        organisationName:
            json['organisation_name'] as String? ?? 'Organisation',
        communityName: json['community_name'] as String? ?? 'Community',
        collectionPurpose: json['collection_purpose'] as String? ??
            'Community funeral assistance contribution',
        amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 2000,
        currencyCode: json['currency_code'] as String? ?? 'BWP',
        status: json['status'] as String? ?? 'unpaid',
        fulfilmentStatus: json['fulfilment_status'] as String? ?? 'pending',
        collectionStatus: json['collection_status'] as String? ?? 'active',
      );

  bool get isPayable =>
      collectionStatus == 'active' &&
      status == 'unpaid' &&
      fulfilmentStatus == 'pending';
  bool get isFulfilled =>
      fulfilmentStatus == 'fulfilled' || status == 'confirmed';
}

final funeralObligationsProvider =
    FutureProvider.autoDispose<List<FuneralObligationItem>>((ref) async {
  final dio = ref.watch(apiClientProvider).dio;
  final response = await dio.get('/api/v1/community/funeral/my/obligations');
  final data = response.data['data'] as List<dynamic>? ?? [];
  return data
      .map((item) =>
          FuneralObligationItem.fromJson(item as Map<String, dynamic>))
      .toList();
}, retry: (retryCount, error) => null);

class FuneralAssistanceScreen extends ConsumerWidget {
  const FuneralAssistanceScreen({super.key, this.collectionId});

  final String? collectionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final asyncObligations = ref.watch(funeralObligationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Funeral Assistance')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(funeralObligationsProvider.future),
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              elevation: 0,
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
              child: const Padding(
                padding: EdgeInsets.all(16),
                child: Row(
                  children: [
                    Icon(Icons.volunteer_activism_outlined, size: 32),
                    SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Community Support',
                            style: TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                          SizedBox(height: 4),
                          Text(
                            'Standard household contribution for verified community funeral collections.',
                            style: TextStyle(fontSize: 13),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),
            asyncObligations.when(
              loading: () => const Center(
                child: Padding(
                  padding: EdgeInsets.all(40),
                  child: CircularProgressIndicator(),
                ),
              ),
              error: (err, _) => Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    children: [
                      Icon(Icons.info_outline,
                          size: 48,
                          color: Theme.of(context)
                              .extension<OkThemeExtension>()!
                              .warningFg),
                      const SizedBox(height: 12),
                      const Text(
                        'Funeral assistance is currently unavailable',
                        style: TextStyle(
                            fontSize: 18, fontWeight: FontWeight.w600),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'The service is not enabled yet. Your household and contribution status will appear here when it is ready.',
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 16),
                      OutlinedButton.icon(
                        onPressed: () =>
                            ref.invalidate(funeralObligationsProvider),
                        icon: const Icon(Icons.refresh),
                        label: const Text('Try again'),
                      ),
                    ],
                  ),
                ),
              ),
              data: (obligations) {
                if (obligations.isEmpty) {
                  return Center(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                          vertical: 40, horizontal: 16),
                      child: Column(
                        children: [
                          Icon(Icons.check_circle_outline,
                              size: 48,
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant),
                          SizedBox(height: 16),
                          Text(
                            'No Outstanding Contributions',
                            style: TextStyle(
                                fontSize: 18, fontWeight: FontWeight.w600),
                          ),
                          SizedBox(height: 8),
                          Text(
                            'Your household currently has no pending funeral assistance obligations.',
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  );
                }

                final ordered = [...obligations];
                if (collectionId != null) {
                  ordered.sort((a, b) => a.collectionId == collectionId
                      ? -1
                      : (b.collectionId == collectionId ? 1 : 0));
                }
                return Column(
                  children: ordered
                      .map((item) => _ObligationCard(item: item))
                      .toList(),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _ObligationCard extends ConsumerWidget {
  final FuneralObligationItem item;

  const _ObligationCard({required this.item});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final amountFormatted =
        '${item.currencyCode} ${(item.amountMinor / 100).toStringAsFixed(2)}';

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Funeral Collection',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                _StatusChip(
                    status: item.status, fulfilment: item.fulfilmentStatus),
              ],
            ),
            const SizedBox(height: 12),
            _CollectionInfoRow(
                icon: Icons.business_outlined, label: item.organisationName),
            const SizedBox(height: 6),
            _CollectionInfoRow(
                icon: Icons.groups_outlined, label: item.communityName),
            const SizedBox(height: 8),
            Text('Purpose', style: Theme.of(context).textTheme.labelMedium),
            const SizedBox(height: 2),
            Text(item.collectionPurpose,
                style: Theme.of(context).textTheme.bodyMedium),
            const SizedBox(height: 12),
            Text(
              amountFormatted,
              style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            Text(
              'Obligation Ref: ${item.id.length > 8 ? item.id.substring(0, 8) : item.id}',
              style:
                  Theme.of(context).textTheme.bodySmall?.copyWith(fontSize: 12),
            ),
            const SizedBox(height: 16),
            if (item.isPayable)
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  icon: const Icon(Icons.payment),
                  label: Text('Contribute $amountFormatted'),
                  onPressed: () async {
                    final result = await Navigator.of(context).push<bool>(
                      MaterialPageRoute(
                        builder: (_) => FuneralContributionCheckoutScreen(
                          item: item,
                          dio: ref.read(apiClientProvider).dio,
                        ),
                      ),
                    );
                    if (context.mounted && result == true) {
                      ref.invalidate(funeralObligationsProvider);
                      Future<void>.delayed(const Duration(seconds: 3), () {
                        if (context.mounted) {
                          ref.invalidate(funeralObligationsProvider);
                        }
                      });
                    }
                  },
                ),
              )
            else if (item.isFulfilled)
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.receipt_long),
                  label: const Text('View Payment History & Receipt'),
                  onPressed: () {
                    context.push('/transactions');
                  },
                ),
              )
            else if (item.collectionStatus != 'active')
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text(
                    'This collection is closed. New contributions are no longer accepted.'),
              ),
          ],
        ),
      ),
    );
  }
}

class FuneralContributionCheckoutScreen extends StatefulWidget {
  const FuneralContributionCheckoutScreen({
    required this.item,
    required this.dio,
    super.key,
  });

  final FuneralObligationItem item;
  final Dio dio;

  @override
  State<FuneralContributionCheckoutScreen> createState() =>
      _FuneralContributionCheckoutScreenState();
}

class _FuneralContributionCheckoutScreenState
    extends State<FuneralContributionCheckoutScreen>
    with WidgetsBindingObserver {
  final _uuid = const Uuid();
  List<Map<String, dynamic>> _options = [];
  String? _selectedProviderAccountId;
  String? _paymentIntentId;
  Map<String, dynamic>? _attempt;
  String? _error;
  bool _loading = true;
  bool _busy = false;
  bool _refreshing = false;
  bool _succeeded = false;
  bool _obligationRecorded = false;
  Timer? _statusTimer;
  late String _intentIdempotencyKey;
  late String _attemptIdempotencyKey;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _intentIdempotencyKey = _uuid.v4();
    _attemptIdempotencyKey = _uuid.v4();
    unawaited(_loadOptions());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _statusTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _paymentIntentId != null) {
      unawaited(_refreshStatus());
    }
  }

  Map<String, dynamic> _responseData(Response<Object?> response) {
    final body = response.data;
    if (body is Map<String, dynamic> && body['data'] is Map<String, dynamic>) {
      return body['data'] as Map<String, dynamic>;
    }
    throw const FormatException('Unexpected payment response.');
  }

  String _readError(Object error, String fallback) {
    if (error is DioException) {
      final body = error.response?.data;
      if (body is Map) {
        final errorObject = body['error'];
        if (errorObject is Map && errorObject['message'] is String) {
          return errorObject['message'] as String;
        }
        if (body['message'] is String) return body['message'] as String;
        if (body['error'] is String) return body['error'] as String;
        final details = errorObject is Map ? errorObject['details'] : null;
        if (details is List && details.isNotEmpty && details.first is Map) {
          final message = (details.first as Map)['message'];
          if (message is String) return message;
        }
      }
    }
    return fallback;
  }

  Future<void> _loadOptions() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final response = await widget.dio.get<Object?>(
        '/api/v1/payment/options',
        queryParameters: {
          'purpose_type': 'funeral_contribution',
          'purpose_reference': widget.item.id,
          'module_key': 'funeral_contribution',
          'currency': widget.item.currencyCode,
          'amount': widget.item.amountMinor,
          'organisation_id': widget.item.organisationId,
        },
      );
      final body = response.data as Map<String, dynamic>;
      final options = (body['data'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .where((option) =>
              (option['provider_account_id'] as String? ?? '').isNotEmpty)
          .toList();
      final defaultOption =
          options.where((option) => option['is_default'] == true).firstOrNull;
      if (!mounted) return;
      setState(() {
        _options = options;
        _selectedProviderAccountId =
            defaultOption?['provider_account_id'] as String? ??
                options.firstOrNull?['provider_account_id'] as String?;
      });
    } catch (error) {
      if (mounted) {
        setState(() => _error =
            _readError(error, 'Could not load bank or card payment methods.'));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Map<String, dynamic>? get _selectedOption => _options
      .where((option) =>
          option['provider_account_id'] == _selectedProviderAccountId)
      .firstOrNull;

  Future<void> _pay() async {
    final option = _selectedOption;
    final providerCode = option?['provider_code']?.toString();
    final providerAccountId = option?['provider_account_id']?.toString();
    if (option == null || providerCode == null || providerAccountId == null)
      return;
    if (widget.item.organisationId.isEmpty) {
      setState(() => _error =
          'This collection is missing its organisation payment scope. Please refresh or contact support.');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_paymentIntentId == null) {
        final intentResponse = await widget.dio.post<Object?>(
          '/api/v1/payment/intents',
          data: {
            'purpose_type': 'funeral_contribution',
            'purpose_reference': widget.item.id,
            'organisation_id': widget.item.organisationId,
            'currency_code': widget.item.currencyCode,
          },
          options: Options(headers: {'Idempotency-Key': _intentIdempotencyKey}),
        );
        final intent = _responseData(intentResponse);
        _paymentIntentId =
            (intent['id'] ?? intent['payment_intent_id'])?.toString();
      }
      final intentId = _paymentIntentId;
      if (intentId == null || intentId.isEmpty) {
        throw const FormatException('Payment intent was not returned.');
      }

      final attemptResponse = await widget.dio.post<Object?>(
        '/api/v1/payment/intents/$intentId/attempts',
        data: {
          'provider_code': providerCode,
          'provider_account_id': providerAccountId,
        },
        options: Options(headers: {'Idempotency-Key': _attemptIdempotencyKey}),
      );
      final attempt = _responseData(attemptResponse);
      if (!mounted) return;
      setState(() => _attempt = attempt);
      _startStatusPolling();

      final actionData = attempt['next_action_data'];
      final actionUrl = actionData is Map
          ? (actionData['url'] ?? actionData['redirect_url'])?.toString()
          : null;
      if (actionUrl != null) {
        final uri = Uri.tryParse(actionUrl);
        if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) {
          await launchUrl(uri, mode: LaunchMode.platformDefault);
        }
      }
    } catch (error) {
      if (mounted) {
        setState(
            () => _error = _readError(error, 'Payment could not be started.'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startStatusPolling() {
    _statusTimer?.cancel();
    _statusTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!_refreshing && !_obligationRecorded && mounted) {
        unawaited(_refreshStatus());
      }
    });
  }

  Future<void> _refreshStatus() async {
    final intentId = _paymentIntentId;
    if (intentId == null || _refreshing) return;
    _refreshing = true;
    try {
      final response = await widget.dio.get<Object?>(
        '/api/v1/payment/intents/$intentId/status',
      );
      final status = _responseData(response);
      final latestAttempt = status['latest_attempt'];
      final statusName = status['status']?.toString().toLowerCase();
      if (!mounted) return;
      var obligationRecorded = false;
      if (statusName == 'succeeded') {
        final obligationsResponse = await widget.dio.get<Object?>(
          '/api/v1/community/funeral/my/obligations',
        );
        final body = obligationsResponse.data;
        final rows = body is Map ? body['data'] : null;
        if (rows is List) {
          obligationRecorded = rows.whereType<Map>().any((row) =>
              row['id'] == widget.item.id &&
              (row['fulfilment_status'] == 'fulfilled' ||
                  row['status'] == 'confirmed'));
        }
      }
      if (!mounted) return;
      setState(() {
        if (latestAttempt is Map<String, dynamic>) {
          _attempt = {...?_attempt, ...latestAttempt};
        }
        _succeeded = statusName == 'succeeded';
        _obligationRecorded = obligationRecorded;
      });
      if (_obligationRecorded) _statusTimer?.cancel();
    } catch (error) {
      if (mounted)
        setState(() =>
            _error = _readError(error, 'Unable to refresh payment status.'));
    } finally {
      _refreshing = false;
    }
  }

  bool get _attemptFailed {
    final status = _attempt?['status']?.toString().toLowerCase();
    return status != null &&
        const {'failed', 'cancelled', 'canceled', 'declined', 'expired'}
            .contains(status);
  }

  String _formatAmount() =>
      '${widget.item.currencyCode} ${(widget.item.amountMinor / 100).toStringAsFixed(2)}';

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(title: const Text('Funeral contribution')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.item.organisationName,
                        style: Theme.of(context).textTheme.titleMedium),
                    const SizedBox(height: 4),
                    Text(widget.item.communityName),
                    const SizedBox(height: 10),
                    Text('Purpose',
                        style: Theme.of(context).textTheme.labelMedium),
                    Text(widget.item.collectionPurpose),
                    const SizedBox(height: 12),
                    Text(_formatAmount(),
                        style: Theme.of(context).textTheme.headlineSmall),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),
            if (_succeeded && _obligationRecorded)
              Card(
                color: colors.primaryContainer,
                child: ListTile(
                  leading: Icon(Icons.check_circle, color: colors.primary),
                  title: const Text('Payment confirmed'),
                  subtitle: const Text(
                      'Your contribution has been recorded against this household.'),
                  trailing: TextButton(
                    onPressed: () => Navigator.of(context).pop(true),
                    child: const Text('Done'),
                  ),
                ),
              )
            else if (_succeeded)
              const Card(
                  child: ListTile(
                      leading: CircularProgressIndicator(),
                      title: Text('Payment received'),
                      subtitle: Text(
                          'Waiting for the funeral contribution record to confirm. This screen will update automatically.')))
            else ...[
              Text('Choose a bank or card',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              if (_loading)
                const Center(child: CircularProgressIndicator())
              else if (_options.isEmpty)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        const Text(
                            'No bank or card payment method is available for this contribution yet.'),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(
                          onPressed: _loadOptions,
                          icon: const Icon(Icons.refresh),
                          label: const Text('Try again'),
                        ),
                      ],
                    ),
                  ),
                )
              else
                ..._options.map((option) {
                  final accountId = option['provider_account_id'].toString();
                  final disabled = (option['availability']?['restrictions']
                              as List<dynamic>?)
                          ?.isNotEmpty ??
                      false;
                  final label = (option['display_label'] ??
                          option['display_name'] ??
                          'Payment provider')
                      .toString();
                  final description = option['display_description']?.toString();
                  return Card(
                    child: RadioListTile<String>(
                      value: accountId,
                      groupValue: _selectedProviderAccountId,
                      onChanged: _busy || disabled
                          ? null
                          : (value) => setState(
                              () => _selectedProviderAccountId = value),
                      secondary: Icon(_paymentIcon(option)),
                      title: Text(label),
                      subtitle: description == null || description.isEmpty
                          ? null
                          : Text(description),
                    ),
                  );
                }),
              if (_attempt != null)
                Card(
                  color: colors.secondaryContainer,
                  child: ListTile(
                    leading: const Icon(Icons.hourglass_top),
                    title: Text(_attemptFailed
                        ? 'Payment was not completed'
                        : 'Waiting for payment confirmation'),
                    subtitle: Text(_attemptFailed
                        ? 'Select a payment method and retry.'
                        : 'Return to this screen after paying. Status refreshes automatically.'),
                    trailing: IconButton(
                      tooltip: 'Refresh status',
                      onPressed: _refreshing ? null : _refreshStatus,
                      icon: const Icon(Icons.refresh),
                    ),
                  ),
                ),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: _busy || _loading || _selectedOption == null
                    ? null
                    : () {
                        if (_attemptFailed) _attemptIdempotencyKey = _uuid.v4();
                        unawaited(_pay());
                      },
                icon: const Icon(Icons.lock_outline),
                label: Text(_busy ? 'Starting payment…' : 'Pay securely'),
              ),
            ],
            if (_busy) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: colors.error)),
            ],
          ],
        ),
      ),
    );
  }

  IconData _paymentIcon(Map<String, dynamic> option) {
    final method = option['payment_method']?.toString().toLowerCase() ?? '';
    final instruments =
        (option['eligible_instrument_types'] as List<dynamic>? ?? const [])
            .map((value) => value.toString().toLowerCase());
    if (method.contains('card') ||
        instruments.any((value) => value.contains('card'))) {
      return Icons.credit_card_outlined;
    }
    if (method.contains('bank') ||
        instruments.any((value) => value.contains('bank'))) {
      return Icons.account_balance_outlined;
    }
    return Icons.payment_outlined;
  }
}

class _CollectionInfoRow extends StatelessWidget {
  const _CollectionInfoRow({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon,
              size: 18, color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(width: 8),
          Expanded(
              child:
                  Text(label, style: Theme.of(context).textTheme.bodyMedium)),
        ],
      );
}

class _StatusChip extends StatelessWidget {
  final String status;
  final String fulfilment;

  const _StatusChip({required this.status, required this.fulfilment});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final semantic = theme.extension<OkThemeExtension>()!;
    Color bg = theme.colorScheme.surfaceContainerHighest;
    Color fg = theme.colorScheme.onSurfaceVariant;
    String label = 'Unknown';

    if (fulfilment == 'fulfilled' || status == 'confirmed') {
      bg = semantic.successBg;
      fg = semantic.successFg;
      label = 'Fulfilled';
    } else if (status == 'unpaid') {
      bg = semantic.warningBg;
      fg = semantic.warningFg;
      label = 'Payment Required';
    } else if (status == 'late_review' || fulfilment == 'held_for_review') {
      bg = semantic.warningBg;
      fg = semantic.warningFg;
      label = 'Under Review';
    } else if (status == 'pending') {
      bg = semantic.infoBg;
      fg = semantic.infoFg;
      label = 'Processing';
    }

    return Chip(
      label: Text(label,
          style:
              TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: fg)),
      backgroundColor: bg,
      padding: EdgeInsets.zero,
      visualDensity: VisualDensity.compact,
    );
  }
}

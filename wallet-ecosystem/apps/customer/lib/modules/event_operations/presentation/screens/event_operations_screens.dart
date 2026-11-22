import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../data/event_operations_service.dart';
import '../event_operations_providers.dart';

class EventOperationsLoginScreen extends ConsumerStatefulWidget {
  const EventOperationsLoginScreen({super.key});

  @override
  ConsumerState<EventOperationsLoginScreen> createState() =>
      _EventOperationsLoginScreenState();
}

class _EventOperationsLoginScreenState
    extends ConsumerState<EventOperationsLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _mfaCode = TextEditingController();
  bool _busy = false;
  bool _obscurePassword = true;
  String? _mfaToken;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _mfaCode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Event team sign in')),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Icon(Icons.event_available_outlined,
                        size: 54, color: theme.colorScheme.primary),
                    const SizedBox(height: 16),
                    Text(
                        _mfaToken == null
                            ? 'OleKard Event Operations'
                            : 'Verify your sign in',
                        style: theme.textTheme.headlineSmall,
                        textAlign: TextAlign.center),
                    const SizedBox(height: 8),
                    Text(
                      _mfaToken == null
                          ? 'Sign in with your event team admin account. Your role controls which actions are available.'
                          : 'Enter the current code from your authenticator app to continue.',
                      style: theme.textTheme.bodyMedium,
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 24),
                    if (_mfaToken == null)
                      TextFormField(
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        autofillHints: const [AutofillHints.username],
                        decoration: const InputDecoration(
                          labelText: 'Work email',
                          prefixIcon: Icon(Icons.email_outlined),
                        ),
                        validator: (value) =>
                            value == null || !value.contains('@')
                                ? 'Enter your work email'
                                : null,
                      ),
                    if (_mfaToken == null) const SizedBox(height: 14),
                    if (_mfaToken == null)
                      TextFormField(
                        controller: _password,
                        obscureText: _obscurePassword,
                        autofillHints: const [AutofillHints.password],
                        decoration: InputDecoration(
                          labelText: 'Password',
                          prefixIcon: const Icon(Icons.lock_outline),
                          suffixIcon: IconButton(
                            tooltip: _obscurePassword
                                ? 'Show password'
                                : 'Hide password',
                            onPressed: () => setState(
                                () => _obscurePassword = !_obscurePassword),
                            icon: Icon(_obscurePassword
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined),
                          ),
                        ),
                        validator: (value) => value == null || value.isEmpty
                            ? 'Enter your password'
                            : null,
                      ),
                    if (_mfaToken != null)
                      TextFormField(
                        controller: _mfaCode,
                        keyboardType: TextInputType.number,
                        autofillHints: const [AutofillHints.oneTimeCode],
                        maxLength: 8,
                        decoration: const InputDecoration(
                          labelText: 'Authenticator code',
                          prefixIcon: Icon(Icons.verified_user_outlined),
                        ),
                        validator: (value) =>
                            value == null || value.trim().length < 6
                                ? 'Enter the authenticator code'
                                : null,
                      ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!,
                          style: TextStyle(color: theme.colorScheme.error)),
                    ],
                    const SizedBox(height: 20),
                    FilledButton.icon(
                      onPressed: _busy
                          ? null
                          : _mfaToken == null
                              ? _signIn
                              : _verifyMfa,
                      icon: _busy
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.login),
                      label:
                          Text(_mfaToken == null ? 'Sign in' : 'Verify code'),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      _mfaToken == null
                          ? 'Use a separate Reviewer or Approver account when the workflow requires independent approval.'
                          : 'Authenticator app codes are supported for multi-factor verification.',
                      style: theme.textTheme.bodySmall,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _signIn() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(eventOperationsServiceProvider).signIn(
            _email.text.trim(),
            _password.text,
          );
      if (!mounted) return;
      if (result['mfa_required'] == true) {
        final methods = (result['methods'] as List<dynamic>? ?? const [])
            .map((method) => method.toString())
            .toSet();
        if (methods.contains('totp')) {
          setState(() => _mfaToken = result['mfa_token']?.toString());
        } else {
          setState(() => _error =
              'This account requires a security key. Sign in through the admin portal on a device configured for that key.');
        }
      } else {
        context.go('/events/operations');
      }
    } on EventOperationsException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not sign in. Try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verifyMfa() async {
    if (!_formKey.currentState!.validate()) return;
    final token = _mfaToken;
    if (token == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(eventOperationsServiceProvider)
          .verifyMfa(token, _mfaCode.text);
      if (mounted) context.go('/events/operations');
    } on EventOperationsException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Could not verify the code. Try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }
}

class EventOperationsHomeScreen extends ConsumerStatefulWidget {
  const EventOperationsHomeScreen({super.key});

  @override
  ConsumerState<EventOperationsHomeScreen> createState() =>
      _EventOperationsHomeScreenState();
}

class _EventOperationsHomeScreenState
    extends ConsumerState<EventOperationsHomeScreen> {
  final _currency = TextEditingController(text: 'BWP');
  final _currencyReference = TextEditingController();
  List<Map<String, dynamic>> _organisations = [];
  List<Map<String, dynamic>> _events = [];
  Map<String, dynamic> _claims = {};
  Map<String, dynamic>? _selectedEvent;
  String? _organisationId;
  String? _error;
  String? _notice;
  bool _loading = true;
  bool _busy = false;

  Set<String> get _permissions =>
      ((_claims['permissions'] as List<dynamic>?) ?? const [])
          .map((value) => value.toString())
          .toSet();
  bool _can(String permission) => _permissions.contains(permission);
  String _organisationName(Map<String, dynamic> row) =>
      (row['display_name'] ?? row['name'] ?? 'Organisation').toString();
  String _eventTitle(Map<String, dynamic> row) =>
      (row['title'] ?? 'Untitled event').toString();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _currency.dispose();
    _currencyReference.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Event Operations'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _busy ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
          IconButton(
            tooltip: 'Sign out of event operations',
            onPressed: _signOut,
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      floatingActionButton: _can('events.event.create')
          ? FloatingActionButton.extended(
              onPressed: _busy || _organisationId == null ? null : _createDraft,
              icon: const Icon(Icons.add),
              label: const Text('Create event'),
            )
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
                children: [
                  _identityCard(theme),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    key: ValueKey(_organisationId),
                    initialValue: _organisationId,
                    decoration: const InputDecoration(
                      labelText: 'Organisation',
                      prefixIcon: Icon(Icons.apartment_outlined),
                      border: OutlineInputBorder(),
                    ),
                    items: _organisations
                        .map((organisation) => DropdownMenuItem(
                              value: organisation['id']?.toString(),
                              child: Text(_organisationName(organisation),
                                  overflow: TextOverflow.ellipsis),
                            ))
                        .toList(),
                    onChanged: _busy ? null : _selectOrganisation,
                  ),
                  const SizedBox(height: 16),
                  if (_error != null) _messageCard(_error!, true),
                  if (_notice != null) _messageCard(_notice!, false),
                  if (_events.isEmpty)
                    _emptyCard()
                  else ...[
                    Text('Events', style: theme.textTheme.titleLarge),
                    const SizedBox(height: 8),
                    for (final event in _events) _eventCard(event),
                  ],
                  if (_selectedEvent != null) ...[
                    const SizedBox(height: 8),
                    _eventDetails(_selectedEvent!),
                  ],
                ],
              ),
            ),
    );
  }

  Widget _identityCard(ThemeData theme) {
    final name =
        (_claims['name'] ?? _claims['email'] ?? 'Event team member').toString();
    final roles = ((_claims['roles'] as List<dynamic>?) ?? const [])
        .map((role) => role.toString().replaceAll('-', ' '))
        .join(', ');
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Icon(Icons.event_note, color: theme.colorScheme.primary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(name, style: theme.textTheme.titleMedium),
                  Text(roles.isEmpty ? 'Event team' : roles,
                      style: theme.textTheme.bodySmall),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _emptyCard() => Card(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            children: [
              const Icon(Icons.event_busy_outlined, size: 36),
              const SizedBox(height: 8),
              Text(_organisationId == null
                  ? 'No active organisation is available for this account.'
                  : 'No events are available for this organisation.'),
              if (_can('events.event.create') && _organisationId != null) ...[
                const SizedBox(height: 8),
                const Text('Use Create event to start a draft.'),
              ],
            ],
          ),
        ),
      );

  Widget _messageCard(String message, bool isError) => Card(
        color: isError
            ? Theme.of(context).colorScheme.errorContainer
            : Theme.of(context).colorScheme.secondaryContainer,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Text(message),
        ),
      );

  Widget _eventCard(Map<String, dynamic> event) {
    final selected =
        event['id']?.toString() == _selectedEvent?['id']?.toString();
    final status =
        (event['status'] ?? event['workflow_state'] ?? 'draft').toString();
    final sales = (event['sales_state'] ?? 'not_published').toString();
    return Card(
      child: ListTile(
        selected: selected,
        leading: const Icon(Icons.confirmation_number_outlined),
        title: Text(_eventTitle(event)),
        subtitle: Text('${_humanize(status)} · Sales ${_humanize(sales)}'),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => _selectEvent(event),
      ),
    );
  }

  Widget _eventDetails(Map<String, dynamic> event) {
    final version = int.tryParse('${event['version']}') ?? 1;
    final categories = (event['categories'] as List<dynamic>? ?? const [])
        .map((raw) => raw is Map ? raw : const <String, dynamic>{})
        .toList();
    final hasPaid = categories
        .any((category) => category['allocation_type']?.toString() == 'paid');
    final currencyAssigned = event['currency_code'] != null &&
        event['currency_code'].toString().isNotEmpty;
    final status =
        (event['status'] ?? event['workflow_state'] ?? 'draft').toString();
    final sales = (event['sales_state'] ?? 'not_published').toString();
    final actorId = _claims['sub']?.toString();
    final createdBy = event['created_by']?.toString();
    final reviewerId = event['event_reviewer_id']?.toString();
    final independentReviewer = actorId != null && actorId != createdBy;
    final independentPriceApprover =
        independentReviewer && actorId != reviewerId;
    final priceApprovedVersion =
        int.tryParse('${event['price_approved_version']}');
    final priceReadyToPublish = !hasPaid ||
        (currencyAssigned &&
            priceApprovedVersion == version &&
            reviewerId != null);
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(_eventTitle(event), style: theme.textTheme.titleLarge),
            const SizedBox(height: 6),
            Text('Version $version · ${_humanize(status)}'),
            Text('Sales: ${_humanize(sales)}'),
            if (event['rejection_reason'] != null &&
                event['rejection_reason'].toString().isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('Review feedback: ${event['rejection_reason']}'),
            ],
            const Divider(height: 24),
            Text('Ticket categories', style: theme.textTheme.titleMedium),
            for (final raw in categories)
              Builder(builder: (context) {
                final allocation = '${raw['allocation_type'] ?? 'paid'}';
                final price = raw['price_minor'] ?? 0;
                return Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Text(
                    "${raw['name'] ?? 'Category'} · ${raw['capacity'] ?? 0} tickets · ${_humanize(allocation)}${allocation == 'paid' ? ' · $price minor units' : ''}",
                  ),
                );
              }),
            if (hasPaid &&
                !currencyAssigned &&
                _can('events.currency.assign')) ...[
              const SizedBox(height: 12),
              TextField(
                controller: _currency,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  labelText: 'Currency code',
                  hintText: 'BWP',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _currencyReference,
                decoration: const InputDecoration(
                  labelText: 'Finance approval reference',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _assignCurrency(event),
                icon: const Icon(Icons.currency_exchange),
                label: const Text('Assign currency'),
              ),
            ],
            const SizedBox(height: 14),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (_can('events.event.submit') &&
                    ['draft', 'rejected', 'change_pending'].contains(status))
                  _actionButton('Submit for review', Icons.send_outlined,
                      () => _transition(event, 'submit-review')),
                if (_can('events.event.review') &&
                    independentReviewer &&
                    status == 'pending_review')
                  _actionButton('Approve review', Icons.fact_check_outlined,
                      () => _transition(event, 'approve-review')),
                if (hasPaid &&
                    currencyAssigned &&
                    _can('events.price.approve') &&
                    independentPriceApprover &&
                    ['pending_review', 'approved'].contains(status) &&
                    !['published', 'cancelled'].contains(status))
                  _actionButton('Approve prices', Icons.price_check_outlined,
                      () => _transition(event, 'approve-prices')),
                if (_can('events.event.publish') &&
                    status == 'approved' &&
                    priceReadyToPublish)
                  _actionButton('Publish', Icons.publish_outlined,
                      () => _transition(event, 'publish')),
                if (_can('events.sales.resume') &&
                    status == 'published' &&
                    sales != 'active')
                  _actionButton('Resume sales', Icons.play_circle_outline,
                      () => _transition(event, 'resume-sales')),
              ],
            ),
            if (!_hasActions(event, hasPaid, currencyAssigned)) ...[
              const SizedBox(height: 8),
              Text(
                'No action is available for your current permissions and this event state.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  bool _hasActions(
      Map<String, dynamic> event, bool hasPaid, bool currencyAssigned) {
    final status =
        (event['status'] ?? event['workflow_state'] ?? 'draft').toString();
    final sales = (event['sales_state'] ?? 'not_published').toString();
    final actorId = _claims['sub']?.toString();
    final createdBy = event['created_by']?.toString();
    final reviewerId = event['event_reviewer_id']?.toString();
    final version = int.tryParse('${event['version']}') ?? 1;
    final priceApprovedVersion =
        int.tryParse('${event['price_approved_version']}');
    final priceReadyToPublish = !hasPaid ||
        (currencyAssigned &&
            priceApprovedVersion == version &&
            reviewerId != null);
    return (_can('events.event.submit') &&
            ['draft', 'rejected', 'change_pending'].contains(status)) ||
        (_can('events.event.review') &&
            actorId != null &&
            actorId != createdBy &&
            status == 'pending_review') ||
        (hasPaid &&
            currencyAssigned &&
            _can('events.price.approve') &&
            actorId != null &&
            actorId != createdBy &&
            actorId != reviewerId &&
            ['pending_review', 'approved'].contains(status) &&
            !['published', 'cancelled'].contains(status)) ||
        (_can('events.event.publish') &&
            status == 'approved' &&
            priceReadyToPublish) ||
        (_can('events.sales.resume') &&
            status == 'published' &&
            sales != 'active') ||
        (hasPaid && !currencyAssigned && _can('events.currency.assign'));
  }

  Widget _actionButton(String label, IconData icon, VoidCallback onPressed) =>
      OutlinedButton.icon(
        onPressed: _busy ? null : onPressed,
        icon: Icon(icon),
        label: Text(label),
      );

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
      _notice = null;
    });
    try {
      final service = ref.read(eventOperationsServiceProvider);
      final claims = await service.currentClaims();
      final organisations = await service.organisations();
      final stillValid =
          organisations.any((row) => row['id']?.toString() == _organisationId);
      final selectedId = stillValid
          ? _organisationId
          : organisations.isEmpty
              ? null
              : organisations.first['id']?.toString();
      final events = selectedId == null
          ? <Map<String, dynamic>>[]
          : await service.events(selectedId);
      if (!mounted) return;
      setState(() {
        _claims = claims;
        _organisations = organisations;
        _organisationId = selectedId;
        _events = events;
        if (_selectedEvent != null) {
          _selectedEvent = events.cast<Map<String, dynamic>?>().firstWhere(
                (row) =>
                    row?['id']?.toString() == _selectedEvent?['id']?.toString(),
                orElse: () => null,
              );
        }
      });
    } on EventOperationsException catch (error) {
      if (error.message.toLowerCase().contains('session') ||
          error.message.toLowerCase().contains('sign in')) {
        await ref.read(eventOperationsServiceProvider).signOut();
        if (mounted) context.go('/events/operations/login');
      } else if (mounted) {
        setState(() => _error = error.message);
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not load event operations.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _selectOrganisation(String? id) {
    setState(() {
      _organisationId = id;
      _selectedEvent = null;
    });
    if (id != null) {
      _loadEvents(id);
    }
  }

  Future<void> _loadEvents(String organisationId) async {
    try {
      final events =
          await ref.read(eventOperationsServiceProvider).events(organisationId);
      if (mounted) setState(() => _events = events);
    } on EventOperationsException catch (error) {
      if (mounted) setState(() => _error = error.message);
    }
  }

  Future<void> _selectEvent(Map<String, dynamic> event) async {
    setState(() {
      _busy = true;
      _error = null;
      _selectedEvent = event;
    });
    try {
      final details = await ref
          .read(eventOperationsServiceProvider)
          .getEvent(event['id'].toString());
      if (mounted) setState(() => _selectedEvent = details);
    } on EventOperationsException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _createDraft() async {
    final organisationId = _organisationId;
    if (organisationId == null) return;
    final organisation = _organisations.firstWhere(
      (row) => row['id']?.toString() == organisationId,
      orElse: () => const <String, dynamic>{},
    );
    final payload = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _CreateEventSheet(
        initialOrganiserName: _organisationName(organisation),
      ),
    );
    if (payload == null || !mounted) {
      return;
    }
    await _runAction(() async {
      final created = await ref
          .read(eventOperationsServiceProvider)
          .createEvent(organisationId, payload);
      _selectedEvent = created;
      _notice = 'Event draft created. Submit it for review when it is ready.';
      _events =
          await ref.read(eventOperationsServiceProvider).events(organisationId);
    });
  }

  Future<void> _assignCurrency(Map<String, dynamic> event) async {
    final reference = _currencyReference.text.trim();
    if (reference.isEmpty) {
      setState(() => _error = 'Enter the Finance approval reference.');
      return;
    }
    await _runAction(() async {
      await ref.read(eventOperationsServiceProvider).assignCurrency(
            event['id'].toString(),
            code: _currency.text.trim(),
            approvalReference: reference,
          );
      _currencyReference.clear();
      await _refreshSelectedEvent(event['id'].toString());
      _notice =
          'Currency assignment recorded. Price approval is a separate step.';
    });
  }

  Future<void> _transition(Map<String, dynamic> event, String action) async {
    final version = int.tryParse('${event['version']}') ?? 1;
    await _runAction(() async {
      await ref.read(eventOperationsServiceProvider).transition(
            event['id'].toString(),
            action,
            version,
          );
      await _refreshSelectedEvent(event['id'].toString());
      _notice = switch (action) {
        'submit-review' => 'Event submitted for review.',
        'approve-review' =>
          'Event review approved. Price approval may still be required.',
        'approve-prices' => 'Ticket prices approved for this version.',
        'publish' =>
          'Event published. Resume sales when the approved sales window is open.',
        'resume-sales' => 'Event sales are open.',
        _ => 'Event updated.',
      };
    });
  }

  Future<void> _refreshSelectedEvent(String id) async {
    final service = ref.read(eventOperationsServiceProvider);
    _selectedEvent = await service.getEvent(id);
    if (_organisationId != null) {
      _events = await service.events(_organisationId!);
    }
  }

  Future<void> _runAction(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      await action();
    } on EventOperationsException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'The event operation could not be completed.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _signOut() async {
    await ref.read(eventOperationsServiceProvider).signOut();
    if (mounted) context.go('/events/operations/login');
  }

  String _humanize(String value) => value
      .replaceAll('_', ' ')
      .split(' ')
      .where((part) => part.isNotEmpty)
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .join(' ');
}

class _CreateEventSheet extends StatefulWidget {
  const _CreateEventSheet({required this.initialOrganiserName});

  final String initialOrganiserName;

  @override
  State<_CreateEventSheet> createState() => _CreateEventSheetState();
}

class _CreateEventSheetState extends State<_CreateEventSheet> {
  final _key = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _organiser = TextEditingController();
  final _capacity = TextEditingController(text: '100');
  final _timezone = TextEditingController(text: 'Africa/Gaborone');
  final _starts = TextEditingController();
  final _ends = TextEditingController();
  final _salesStarts = TextEditingController();
  final _salesEnds = TextEditingController();
  final List<_EventCategoryDraft> _categories = [_EventCategoryDraft()];
  final _country = TextEditingController(text: 'BW');
  final _entryPolicy = TextEditingController(
      text: 'Present a valid event ticket at the entrance.');
  final _cancellationPolicy = TextEditingController(
      text: 'Contact the organiser for event cancellation information.');
  final _transferPolicy = TextEditingController(
      text:
          'Tickets are non-transferable unless the organiser advises otherwise.');
  String _format = 'physical';
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _organiser.text = widget.initialOrganiserName;
  }

  @override
  void dispose() {
    for (final controller in [
      _title,
      _organiser,
      _capacity,
      _timezone,
      _starts,
      _ends,
      _salesStarts,
      _salesEnds,
      _country,
      _entryPolicy,
      _cancellationPolicy,
      _transferPolicy,
    ]) {
      controller.dispose();
    }
    for (final category in _categories) {
      category.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Create event draft'),
          leading: IconButton(
            icon: const Icon(Icons.close),
            onPressed: () => Navigator.pop(context),
          ),
        ),
        body: Form(
          key: _key,
          child: ListView(
            padding: EdgeInsets.fromLTRB(
                16, 12, 16, MediaQuery.of(context).viewInsets.bottom + 24),
            children: [
              const Text('Event details',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
              const SizedBox(height: 12),
              _field(_title, 'Event name', required: true),
              _field(_organiser, 'Organiser name', required: true),
              DropdownButtonFormField<String>(
                initialValue: _format,
                decoration: const InputDecoration(
                    labelText: 'Event format', border: OutlineInputBorder()),
                items: const [
                  DropdownMenuItem(value: 'physical', child: Text('Physical')),
                  DropdownMenuItem(value: 'online', child: Text('Online')),
                  DropdownMenuItem(value: 'hybrid', child: Text('Hybrid')),
                ],
                onChanged: (value) =>
                    setState(() => _format = value ?? 'physical'),
              ),
              if (_format == 'physical') ...[
                const SizedBox(height: 12),
                _field(_country, 'Venue country code',
                    required: true, maxLength: 2),
              ],
              _field(_capacity, 'Event capacity',
                  required: true, number: true, minimum: 1),
              _field(_timezone, 'Time zone (IANA)',
                  required: true, hint: 'Africa/Gaborone'),
              _dateField(_starts, 'Event starts (ISO 8601 with UTC offset)'),
              _dateField(_ends, 'Event ends (ISO 8601 with UTC offset)'),
              _dateField(_salesStarts, 'Ticket sales start (ISO 8601)'),
              _dateField(_salesEnds, 'Ticket sales end (ISO 8601)'),
              const SizedBox(height: 16),
              Row(
                children: [
                  const Expanded(
                    child: Text('Ticket categories',
                        style: TextStyle(
                            fontSize: 18, fontWeight: FontWeight.w600)),
                  ),
                  TextButton.icon(
                    onPressed: () =>
                        setState(() => _categories.add(_EventCategoryDraft())),
                    icon: const Icon(Icons.add),
                    label: const Text('Add category'),
                  ),
                ],
              ),
              for (var index = 0; index < _categories.length; index++)
                _categoryFields(index),
              const SizedBox(height: 16),
              const Text('Policies',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
              const SizedBox(height: 12),
              _field(_entryPolicy, 'Entry policy', required: true, maxLines: 3),
              _field(_cancellationPolicy, 'Cancellation policy',
                  required: true, maxLines: 3),
              _field(_transferPolicy, 'Transfer policy',
                  required: true, maxLines: 3),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(_error!,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error)),
              ],
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _submitting ? null : _save,
                icon: _submitting
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.save_outlined),
                label: const Text('Create draft'),
              ),
            ],
          ),
        ),
      );

  Widget _field(TextEditingController controller, String label,
          {bool required = false,
          bool number = false,
          int? minimum,
          String? hint,
          int? maxLength,
          int maxLines = 1}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(
          controller: controller,
          keyboardType: number ? TextInputType.number : TextInputType.text,
          maxLength: maxLength,
          maxLines: maxLines,
          decoration: InputDecoration(
              labelText: label,
              hintText: hint,
              border: const OutlineInputBorder()),
          validator: (value) {
            if (required && (value == null || value.trim().isEmpty)) {
              return 'Required';
            }
            if (number &&
                value != null &&
                value.isNotEmpty &&
                int.tryParse(value) == null) {
              return 'Enter a whole number';
            }
            if (minimum != null &&
                value != null &&
                value.isNotEmpty &&
                (int.tryParse(value) ?? 0) < minimum) {
              return 'Enter a value of at least $minimum';
            }
            return null;
          },
        ),
      );

  Widget _dateField(TextEditingController controller, String label) => _field(
        controller,
        label,
        required: true,
        hint: '2026-12-15T18:00:00+02:00',
      );

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    final capacity = int.parse(_capacity.text);
    final categoryCapacity = _categories.fold<int>(
      0,
      (total, category) => total + (int.tryParse(category.capacity.text) ?? 0),
    );
    final starts = DateTime.tryParse(_starts.text);
    final ends = DateTime.tryParse(_ends.text);
    final salesStarts = DateTime.tryParse(_salesStarts.text);
    final salesEnds = DateTime.tryParse(_salesEnds.text);
    String? scheduleError;
    if (starts == null ||
        ends == null ||
        salesStarts == null ||
        salesEnds == null) {
      scheduleError =
          'Enter valid ISO 8601 date and time values including the UTC offset.';
    } else if (!starts.isAfter(DateTime.now())) {
      scheduleError = 'Event start must be in the future.';
    } else if (!starts.isBefore(ends)) {
      scheduleError = 'Event start must be before event end.';
    } else if (!salesStarts.isBefore(salesEnds)) {
      scheduleError = 'Ticket sales start must be before ticket sales end.';
    } else if (!salesEnds.isBefore(starts)) {
      scheduleError = 'Ticket sales must end before event start.';
    } else if (categoryCapacity > capacity) {
      scheduleError = 'Ticket category capacity cannot exceed event capacity.';
    }
    if (scheduleError != null) {
      setState(() => _error = scheduleError);
      return;
    }
    setState(() {
      _submitting = true;
      _error = null;
    });
    Navigator.pop(context, {
      'title': _title.text.trim(),
      'image_url': null,
      'event_format': _format,
      'organiser_name': _organiser.text.trim(),
      'sponsors': <String>[],
      'capacity': capacity,
      'timezone': _timezone.text.trim(),
      'starts_at': _starts.text.trim(),
      'ends_at': _ends.text.trim(),
      'sales_start_at': _salesStarts.text.trim(),
      'sales_end_at': _salesEnds.text.trim(),
      'max_tickets_per_order': 4,
      'cancellation_policy': _cancellationPolicy.text.trim(),
      'transfer_policy': _transferPolicy.text.trim(),
      'entry_policy': _entryPolicy.text.trim(),
      if (_format == 'physical')
        'venue_country_code': _country.text.trim().toUpperCase(),
      'categories': _categories.map((category) {
        final allocation = category.allocation;
        return {
          'name': category.name.text.trim(),
          'capacity': int.parse(category.capacity.text),
          'allocation_type': allocation,
          'price_minor':
              allocation == 'paid' ? int.parse(category.priceMinor.text) : 0,
          if (allocation == 'free') 'max_per_person': 4,
        };
      }).toList(),
    });
  }

  Widget _categoryFields(int index) {
    final category = _categories[index];
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('Category ${index + 1}',
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
                if (_categories.length > 1)
                  IconButton(
                    tooltip: 'Remove category',
                    onPressed: () => setState(() {
                      _categories.removeAt(index).dispose();
                    }),
                    icon: const Icon(Icons.delete_outline),
                  ),
              ],
            ),
            _field(category.name, 'Category name', required: true),
            _field(category.capacity, 'Category capacity',
                required: true, number: true, minimum: 1),
            DropdownButtonFormField<String>(
              key: ValueKey('allocation-$index-${category.allocation}'),
              initialValue: category.allocation,
              decoration: const InputDecoration(
                  labelText: 'Allocation type', border: OutlineInputBorder()),
              items: const [
                DropdownMenuItem(
                    value: 'free', child: Text('Free registration')),
                DropdownMenuItem(value: 'paid', child: Text('Paid ticket')),
              ],
              onChanged: (value) =>
                  setState(() => category.allocation = value ?? 'free'),
            ),
            if (category.allocation == 'paid') ...[
              const SizedBox(height: 12),
              _field(category.priceMinor,
                  'Price in minor units (2500 = BWP 25.00)',
                  required: true, number: true, minimum: 1),
            ],
          ],
        ),
      ),
    );
  }
}

class _EventCategoryDraft {
  final name = TextEditingController();
  final capacity = TextEditingController(text: '100');
  final priceMinor = TextEditingController(text: '2500');
  String allocation = 'free';

  void dispose() {
    name.dispose();
    capacity.dispose();
    priceMinor.dispose();
  }
}

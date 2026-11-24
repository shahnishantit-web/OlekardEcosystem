import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';

final eventGateApiProvider = Provider<Dio>((_) => createDio(null));

class EventGateScanScreen extends ConsumerStatefulWidget {
  const EventGateScanScreen({super.key});

  @override
  ConsumerState<EventGateScanScreen> createState() =>
      _EventGateScanScreenState();
}

class _EventGateScanScreenState extends ConsumerState<EventGateScanScreen> {
  final _manualEventId = TextEditingController();
  final _manualGateId = TextEditingController();
  final _credential = TextEditingController();
  final _scanner = MobileScannerController();
  List<_AssignedGate> _assignments = const [];
  String? _selectedEventId;
  String? _selectedGateId;
  String? _assignmentError;
  bool _loadingAssignments = true;
  bool _manualIdsExpanded = false;
  bool _manualCredentialExpanded = false;
  bool _scannerStep = false;
  bool _busy = false;
  bool _scanEnabled = true;
  String? _status;
  String? _category;
  String? _resultReason;
  String? _inputError;

  List<_AssignedEvent> get _events {
    final events = <String, _AssignedEvent>{};
    for (final assignment in _assignments) {
      events.putIfAbsent(
        assignment.eventId,
        () => _AssignedEvent(
          id: assignment.eventId,
          title: assignment.eventTitle,
          startsAt: assignment.eventStartsAt,
        ),
      );
    }
    return events.values.toList();
  }

  List<_AssignedGate> get _selectedEventGates => _assignments
      .where((assignment) => assignment.eventId == _selectedEventId)
      .toList();

  String get _activeEventId =>
      _manualIdsExpanded ? _manualEventId.text.trim() : _selectedEventId ?? '';

  String get _activeGateId =>
      _manualIdsExpanded ? _manualGateId.text.trim() : _selectedGateId ?? '';

  String get _activeEventLabel {
    if (_manualIdsExpanded) return 'Manually entered event';
    return _events
            .where((event) => event.id == _selectedEventId)
            .firstOrNull
            ?.label ??
        'No event selected';
  }

  String get _activeGateLabel {
    if (_manualIdsExpanded) return 'Manually entered gate';
    return _assignments
            .where((gate) => gate.gateId == _selectedGateId)
            .firstOrNull
            ?.gateName ??
        'No gate selected';
  }

  void _continueToScanner() {
    if (_activeEventId.isEmpty || _activeGateId.isEmpty) {
      setState(() {
        _inputError = _manualIdsExpanded
            ? 'Enter both the Event ID and Gate ID to continue.'
            : 'Select an assigned event and gate to continue.';
      });
      return;
    }
    setState(() {
      _scannerStep = true;
      _status = null;
      _category = null;
      _resultReason = null;
      _inputError = null;
      _scanEnabled = true;
    });
  }

  @override
  void initState() {
    super.initState();
    unawaited(_loadGateAssignments());
  }

  @override
  void dispose() {
    _manualEventId.dispose();
    _manualGateId.dispose();
    _credential.dispose();
    _scanner.dispose();
    super.dispose();
  }

  Future<void> _loadGateAssignments() async {
    try {
      final response = await ref
          .read(eventGateApiProvider)
          .get<Map<String, dynamic>>('/v1/events/my-gate-assignments');
      final rows = response.data?['data'];
      final assignments = rows is List
          ? rows
              .whereType<Map>()
              .map((row) => _AssignedGate.fromJson(
                    Map<String, dynamic>.from(row),
                  ))
              .where((assignment) =>
                  assignment.eventId.isNotEmpty &&
                  assignment.gateId.isNotEmpty &&
                  assignment.eventTitle.isNotEmpty &&
                  assignment.gateName.isNotEmpty)
              .toList()
          : <_AssignedGate>[];
      if (!mounted) return;
      setState(() {
        _assignments = assignments;
        _selectedEventId =
            assignments.isEmpty ? null : assignments.first.eventId;
        _selectedGateId = assignments.isEmpty ? null : assignments.first.gateId;
        _loadingAssignments = false;
      });
    } on DioException {
      if (!mounted) return;
      setState(() {
        _loadingAssignments = false;
        _assignmentError =
            'Could not load assigned events. Check your connection and retry.';
      });
    }
  }

  void _selectEvent(String? eventId) {
    final gates = _assignments
        .where((assignment) => assignment.eventId == eventId)
        .toList();
    setState(() {
      _selectedEventId = eventId;
      _selectedGateId = gates.isEmpty ? null : gates.first.gateId;
      _status = null;
      _resultReason = null;
      _inputError = null;
    });
  }

  void _selectGate(String? gateId) {
    setState(() {
      _selectedGateId = gateId;
      _status = null;
      _resultReason = null;
      _inputError = null;
    });
  }

  Future<void> _validate(String value) async {
    if (_busy) return;
    final eventId = _activeEventId;
    final gateId = _activeGateId;
    if (eventId.isEmpty || gateId.isEmpty) {
      setState(() {
        _inputError = _manualIdsExpanded
            ? 'Enter the Event ID and Gate ID before scanning.'
            : 'Select an assigned event and gate before scanning.';
      });
      return;
    }
    if (value.isEmpty) {
      setState(() {
        _inputError = 'Scan a ticket or enter its credential manually.';
      });
      return;
    }
    setState(() {
      _busy = true;
      _scanEnabled = false;
      _status = null;
      _category = null;
      _resultReason = null;
      _inputError = null;
    });
    try {
      final response =
          await ref.read(eventGateApiProvider).post<Map<String, dynamic>>(
        '/v1/events/$eventId/gates/$gateId/validate',
        data: {'credential': value},
      );
      final data = response.data?['data'] as Map<String, dynamic>?;
      final returnedStatus = data?['status'] as String?;
      const knownStatuses = {'admitted', 'already_admitted', 'denied'};
      if (mounted) {
        setState(() {
          _status = knownStatuses.contains(returnedStatus)
              ? returnedStatus
              : 'unknown';
          _category =
              _status == 'unknown' ? null : data?['category'] as String?;
          _resultReason = _status == 'denied'
              ? 'This ticket is not valid for admission at this event and gate.'
              : null;
        });
      }
    } on DioException catch (exception) {
      if (!mounted) return;
      final responseStatus = exception.response?.statusCode;
      // A received client-error response is a definitive rejection. A lost,
      // timed-out, or server-error response may follow a committed admission.
      // Never present that ambiguous outcome as either admitted or denied.
      const definitiveRejections = {400, 401, 403, 404, 422};
      final responseData = exception.response?.data;
      final errorData = responseData is Map ? responseData['error'] : null;
      final message = errorData is Map ? errorData['message'] : null;
      setState(() {
        _status = definitiveRejections.contains(responseStatus)
            ? 'denied'
            : 'unknown';
        _resultReason = _status == 'denied'
            ? responseStatus == 403
                ? 'This account is not assigned to validate tickets at this gate.'
                : responseStatus == 404
                    ? 'The selected event or gate is unavailable.'
                    : message is String
                        ? message
                        : 'This ticket could not be admitted.'
            : 'The server did not confirm the result. Do not admit the attendee yet.';
      });
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _scanEnabled = false;
        });
      }
      _credential.clear();
    }
  }

  void _onDetected(BarcodeCapture capture) {
    if (!_scanEnabled || _busy) return;
    final value = capture.barcodes.firstOrNull?.rawValue;
    if (value != null) _validate(value);
  }

  void _scanNext() {
    setState(() {
      _status = null;
      _category = null;
      _resultReason = null;
      _inputError = null;
      _scanEnabled = true;
    });
  }

  Color _resultColor() => switch (_status) {
        'admitted' => AppColors.success,
        'already_admitted' => AppColors.warning,
        'denied' => AppColors.error,
        _ => AppColors.info,
      };

  IconData _resultIcon() => switch (_status) {
        'admitted' => Icons.check_circle_rounded,
        'already_admitted' => Icons.warning_amber_rounded,
        'denied' => Icons.block_rounded,
        _ => Icons.help_outline_rounded,
      };

  String _resultTitle() => switch (_status) {
        'admitted' => 'Admit',
        'already_admitted' => 'Already admitted',
        'denied' => 'Denied',
        _ => 'Admission not confirmed',
      };

  @override
  Widget build(BuildContext context) {
    final isOnline = ref.watch(connectivityProvider);
    final events = _events;
    final gates = _selectedEventGates;

    return Scaffold(
      appBar: AppBar(
        automaticallyImplyLeading: false,
        leading: _scannerStep
            ? IconButton(
                tooltip: 'Back to event and gate selection',
                onPressed: () => setState(() {
                  _scannerStep = false;
                  _scanEnabled = false;
                  _status = null;
                  _resultReason = null;
                  _inputError = null;
                }),
                icon: const Icon(Icons.arrow_back),
              )
            : null,
        title:
            Text(_scannerStep ? 'Scan event tickets' : 'Event Gate Admission'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (!isOnline) ...[
            const OfflineBanner(),
            const SizedBox(height: AppSpacing.sm),
          ],
          Padding(
            padding: const EdgeInsets.only(
              left: AppSpacing.xs,
              right: AppSpacing.xs,
              bottom: AppSpacing.md,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_scannerStep ? 'Ticket validation' : 'Gate check-in',
                    style: AppTypography.headlineLarge),
                const SizedBox(height: AppSpacing.xs),
                Text(
                  _scannerStep
                      ? 'Scan a ticket QR code or enter its credential.'
                      : 'Choose an assigned event and gate to begin.',
                  style: AppTypography.bodyMedium
                      .copyWith(color: AppColors.textSecondary),
                ),
              ],
            ),
          ),
          if (!_scannerStep)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Container(
                          width: 42,
                          height: 42,
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(
                                AppSpacing.borderRadiusSm),
                          ),
                          child: const Icon(Icons.event_available_outlined,
                              color: AppColors.primaryLight),
                        ),
                        const SizedBox(width: AppSpacing.md),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('Event and gate',
                                  style: AppTypography.titleLarge),
                              Text(
                                'Choose your assigned admission point.',
                                style: AppTypography.bodySmall
                                    .copyWith(color: AppColors.textSecondary),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    if (_loadingAssignments)
                      const LinearProgressIndicator()
                    else if (!_manualIdsExpanded)
                      DropdownButtonFormField<String>(
                        initialValue: _selectedEventId,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Event',
                          prefixIcon: Icon(Icons.event_outlined),
                        ),
                        hint: const Text('No assigned events found'),
                        items: events
                            .map((event) => DropdownMenuItem(
                                  value: event.id,
                                  child: Text(
                                    event.label,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ))
                            .toList(),
                        onChanged: events.isEmpty ? null : _selectEvent,
                      )
                    else
                      const ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(Icons.edit_outlined),
                        title: Text('Using manually entered IDs'),
                        subtitle: Text(
                            'Switch back to assigned event selection below.'),
                      ),
                    if (!_loadingAssignments && !_manualIdsExpanded) ...[
                      const SizedBox(height: AppSpacing.sm),
                      DropdownButtonFormField<String>(
                        initialValue: _selectedGateId,
                        isExpanded: true,
                        decoration: const InputDecoration(
                          labelText: 'Gate',
                          prefixIcon: Icon(Icons.meeting_room_outlined),
                        ),
                        hint: const Text('No assigned gates for this event'),
                        items: gates
                            .map((gate) => DropdownMenuItem(
                                  value: gate.gateId,
                                  child: Text(gate.gateName),
                                ))
                            .toList(),
                        onChanged: gates.isEmpty ? null : _selectGate,
                      ),
                    ],
                    ExpansionTile(
                      tilePadding: EdgeInsets.zero,
                      childrenPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.tune),
                      title: const Text('Advanced: enter IDs manually'),
                      onExpansionChanged: (expanded) => setState(() {
                        _manualIdsExpanded = expanded;
                        _inputError = null;
                      }),
                      children: [
                        TextField(
                          controller: _manualEventId,
                          textInputAction: TextInputAction.next,
                          onChanged: (_) => setState(() => _inputError = null),
                          decoration: const InputDecoration(
                            labelText: 'Event ID',
                            prefixIcon: Icon(Icons.event_outlined),
                          ),
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        TextField(
                          controller: _manualGateId,
                          textInputAction: TextInputAction.next,
                          onChanged: (_) => setState(() => _inputError = null),
                          decoration: const InputDecoration(
                            labelText: 'Gate ID',
                            prefixIcon: Icon(Icons.meeting_room_outlined),
                          ),
                        ),
                      ],
                    ),
                    if (!_loadingAssignments && _assignmentError != null) ...[
                      Text(
                        _assignmentError!,
                        style: AppTypography.bodySmall
                            .copyWith(color: AppColors.warning),
                      ),
                      TextButton.icon(
                        onPressed: () {
                          setState(() {
                            _loadingAssignments = true;
                            _assignmentError = null;
                          });
                          unawaited(_loadGateAssignments());
                        },
                        icon: const Icon(Icons.refresh),
                        label: const Text('Retry loading assignments'),
                      ),
                    ],
                    if (!_loadingAssignments &&
                        _assignmentError == null &&
                        _assignments.isEmpty &&
                        !_manualIdsExpanded) ...[
                      const SizedBox(height: AppSpacing.sm),
                      Container(
                        padding: const EdgeInsets.all(AppSpacing.sm),
                        decoration: BoxDecoration(
                          color: AppColors.warningLight,
                          borderRadius:
                              BorderRadius.circular(AppSpacing.borderRadiusSm),
                          border: Border.all(color: AppColors.warning),
                        ),
                        child: const Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(Icons.info_outline, color: AppColors.warning),
                            SizedBox(width: AppSpacing.sm),
                            Expanded(
                              child: Text(
                                'No active gate assignment found. Ask an event manager to assign a gate, or enter authorized IDs manually.',
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          if (_scannerStep)
            Card(
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md,
                  vertical: AppSpacing.sm,
                ),
                child: Row(
                  children: [
                    const Icon(Icons.event_available_outlined,
                        color: AppColors.primaryLight),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(_activeEventLabel,
                              style: AppTypography.titleMedium),
                          Text(_activeGateLabel,
                              style: AppTypography.bodySmall
                                  .copyWith(color: AppColors.textSecondary)),
                        ],
                      ),
                    ),
                    TextButton(
                      onPressed: () => setState(() {
                        _scannerStep = false;
                        _scanEnabled = false;
                        _status = null;
                        _resultReason = null;
                      }),
                      child: const Text('Change'),
                    ),
                  ],
                ),
              ),
            ),
          if (!_scannerStep) ...[
            const SizedBox(height: AppSpacing.md),
            FilledButton.icon(
              onPressed: _loadingAssignments ? null : _continueToScanner,
              icon: const Icon(Icons.qr_code_scanner),
              label: const Text('Continue to scanner'),
            ),
          ],
          if (_scannerStep) ...[
            const SizedBox(height: AppSpacing.md),
            if (_status != null)
              _AdmissionResultCard(
                status: _status!,
                category: _category,
                reason: _resultReason,
                color: _resultColor(),
                icon: _resultIcon(),
                title: _resultTitle(),
                onScanNext: isOnline ? _scanNext : null,
              )
            else ...[
              Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Scan ticket',
                            style: AppTypography.titleLarge),
                        const SizedBox(height: AppSpacing.xs),
                        Text(
                          'Position the QR code inside the frame.',
                          style: AppTypography.bodySmall
                              .copyWith(color: AppColors.textSecondary),
                        ),
                      ],
                    ),
                  ),
                  if (isOnline)
                    const _OnlineStatus()
                  else
                    const _OfflineStatus(),
                  if (_busy)
                    const Padding(
                      padding: EdgeInsets.only(left: AppSpacing.sm),
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  else if (isOnline)
                    IconButton(
                      padding: const EdgeInsets.only(left: AppSpacing.xs),
                      tooltip:
                          _scanEnabled ? 'Pause scanner' : 'Resume scanner',
                      onPressed: () =>
                          setState(() => _scanEnabled = !_scanEnabled),
                      icon: Icon(
                        _scanEnabled
                            ? Icons.pause_circle_outline
                            : Icons.play_circle_outline,
                        color: AppColors.primaryLight,
                      ),
                    ),
                ],
              ),
              if (_scanEnabled && isOnline)
                Container(
                  padding: const EdgeInsets.all(AppSpacing.xs),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius:
                        BorderRadius.circular(AppSpacing.borderRadius),
                    border: Border.all(color: AppColors.outline),
                  ),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(
                      AppSpacing.borderRadiusSm,
                    ),
                    child: SizedBox(
                      height: 292,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          MobileScanner(
                            controller: _scanner,
                            onDetect: _onDetected,
                          ),
                          Positioned(
                            left: AppSpacing.sm,
                            top: AppSpacing.sm,
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                color: AppColors.background
                                    .withValues(alpha: 0.82),
                                borderRadius: BorderRadius.circular(20),
                              ),
                              child: Padding(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: AppSpacing.sm,
                                  vertical: AppSpacing.xs,
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      width: 8,
                                      height: 8,
                                      decoration: const BoxDecoration(
                                        shape: BoxShape.circle,
                                        color: AppColors.success,
                                      ),
                                    ),
                                    const SizedBox(width: AppSpacing.xs),
                                    Text(
                                      'LIVE SCANNER',
                                      style: AppTypography.labelSmall.copyWith(
                                        color: AppColors.textPrimary,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                          IgnorePointer(
                            child: Container(
                              width: 248,
                              height: 164,
                              decoration: BoxDecoration(
                                border: Border.all(
                                  color: AppColors.primaryLight,
                                  width: 3,
                                ),
                                borderRadius: BorderRadius.circular(18),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                )
              else if (!isOnline)
                const Card(
                  child: Padding(
                    padding: EdgeInsets.all(AppSpacing.lg),
                    child: Text(
                        'Connect to the internet to scan and validate tickets.'),
                  ),
                )
              else
                const Card(
                  child: Padding(
                    padding: EdgeInsets.all(AppSpacing.lg),
                    child:
                        Text('Scanner paused. Resume it when you are ready.'),
                  ),
                ),
            ],
            if (_status == null)
              OutlinedButton.icon(
                onPressed: () => setState(() {
                  _manualCredentialExpanded = !_manualCredentialExpanded;
                  _inputError = null;
                }),
                icon: Icon(
                  _manualCredentialExpanded
                      ? Icons.close_rounded
                      : Icons.keyboard_outlined,
                ),
                label: Text(_manualCredentialExpanded
                    ? 'Close manual entry'
                    : 'Enter ticket credential manually'),
              ),
            if (_status == null && _manualCredentialExpanded)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'Manual validation',
                        style: AppTypography.titleMedium,
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      Text(
                        'Use this when the ticket QR cannot be scanned.',
                        style: AppTypography.bodySmall
                            .copyWith(color: AppColors.textSecondary),
                      ),
                      const SizedBox(height: AppSpacing.md),
                      TextField(
                        controller: _credential,
                        textInputAction: TextInputAction.done,
                        onChanged: (_) => setState(() => _inputError = null),
                        decoration: const InputDecoration(
                          labelText: 'Ticket credential',
                          hintText: 'Enter the ticket credential',
                          prefixIcon: Icon(Icons.confirmation_number_outlined),
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      FilledButton.icon(
                        onPressed: _busy || !isOnline
                            ? null
                            : () => _validate(_credential.text.trim()),
                        icon: const Icon(Icons.verified_outlined),
                        label:
                            Text(_busy ? 'Checking…' : 'Validate credential'),
                      ),
                    ],
                  ),
                ),
              ),
          ],
          if (_inputError != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Container(
              padding: const EdgeInsets.all(AppSpacing.sm),
              decoration: BoxDecoration(
                color: AppColors.errorLight,
                borderRadius: BorderRadius.circular(AppSpacing.borderRadiusSm),
                border: Border.all(color: AppColors.error),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.error_outline, color: AppColors.error),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Text(
                      _inputError!,
                      style: AppTypography.bodySmall
                          .copyWith(color: AppColors.textPrimary),
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: AppSpacing.sm),
          TextButton.icon(
            onPressed: () => context.pop(),
            icon: const Icon(Icons.close),
            label: const Text('Close'),
          ),
        ],
      ),
    );
  }
}

class _AssignedGate {
  final String eventId;
  final String eventTitle;
  final String eventStartsAt;
  final String gateId;
  final String gateName;

  const _AssignedGate({
    required this.eventId,
    required this.eventTitle,
    required this.eventStartsAt,
    required this.gateId,
    required this.gateName,
  });

  factory _AssignedGate.fromJson(Map<String, dynamic> json) => _AssignedGate(
        eventId: json['event_id'] as String? ?? '',
        eventTitle: json['event_title'] as String? ?? '',
        eventStartsAt: json['event_starts_at'] as String? ?? '',
        gateId: json['gate_id'] as String? ?? '',
        gateName: json['gate_name'] as String? ?? '',
      );
}

class _AssignedEvent {
  final String id;
  final String title;
  final String startsAt;

  const _AssignedEvent({
    required this.id,
    required this.title,
    required this.startsAt,
  });

  String get label {
    final date = DateTime.tryParse(startsAt)?.toLocal();
    if (date == null) return title;
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return '$title · ${date.day} ${months[date.month - 1]} ${date.year}';
  }
}

class _AdmissionResultCard extends StatelessWidget {
  final String status;
  final String? category;
  final String? reason;
  final String title;
  final Color color;
  final IconData icon;
  final VoidCallback? onScanNext;

  const _AdmissionResultCard({
    required this.status,
    required this.category,
    required this.reason,
    required this.title,
    required this.color,
    required this.icon,
    required this.onScanNext,
  });

  @override
  Widget build(BuildContext context) {
    final subtitle = status == 'unknown'
        ? reason ?? 'Do not admit until the server confirms the result.'
        : status == 'admitted'
            ? category == null
                ? 'Ticket validated successfully.'
                : 'Ticket category: $category'
            : reason ??
                (category == null ? null : 'Ticket category: $category');

    return Card(
      color: color.withValues(alpha: 0.14),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          children: [
            Icon(icon, size: 56, color: color),
            const SizedBox(height: AppSpacing.sm),
            Text(
              title,
              textAlign: TextAlign.center,
              style: AppTypography.headlineMedium.copyWith(color: color),
            ),
            if (subtitle != null) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(subtitle, textAlign: TextAlign.center),
            ],
            const SizedBox(height: AppSpacing.md),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: onScanNext,
                icon: const Icon(Icons.qr_code_scanner),
                label: const Text('Scan next ticket'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OnlineStatus extends StatelessWidget {
  const _OnlineStatus();

  @override
  Widget build(BuildContext context) => const _ConnectionStatus(
        label: 'Online',
        color: AppColors.success,
        icon: Icons.cloud_done_outlined,
      );
}

class _OfflineStatus extends StatelessWidget {
  const _OfflineStatus();

  @override
  Widget build(BuildContext context) => const _ConnectionStatus(
        label: 'Offline',
        color: AppColors.warning,
        icon: Icons.cloud_off_outlined,
      );
}

class _ConnectionStatus extends StatelessWidget {
  final String label;
  final Color color;
  final IconData icon;

  const _ConnectionStatus({
    required this.label,
    required this.color,
    required this.icon,
  });

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: AppSpacing.xs),
          Text(label, style: AppTypography.labelSmall.copyWith(color: color)),
        ],
      );
}

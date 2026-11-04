import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';

final eventGateApiProvider = Provider<Dio>((_) => createDio(null));

class EventGateScanScreen extends ConsumerStatefulWidget {
  const EventGateScanScreen({super.key});

  @override
  ConsumerState<EventGateScanScreen> createState() =>
      _EventGateScanScreenState();
}

class _EventGateScanScreenState extends ConsumerState<EventGateScanScreen> {
  final _eventId = TextEditingController();
  final _gateId = TextEditingController();
  final _credential = TextEditingController();
  final _scanner = MobileScannerController();
  bool _busy = false;
  bool _scanEnabled = false;
  String? _status;
  String? _category;

  @override
  void dispose() {
    _eventId.dispose();
    _gateId.dispose();
    _credential.dispose();
    _scanner.dispose();
    super.dispose();
  }

  Future<void> _validate(String value) async {
    if (_busy ||
        value.isEmpty ||
        _eventId.text.isEmpty ||
        _gateId.text.isEmpty) {
      return;
    }
    setState(() {
      _busy = true;
      _scanEnabled = false;
      _status = null;
      _category = null;
    });
    try {
      final response =
          await ref.read(eventGateApiProvider).post<Map<String, dynamic>>(
        '/v1/events/${_eventId.text.trim()}/gates/${_gateId.text.trim()}/validate',
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
        });
      }
    } on DioException catch (exception) {
      if (!mounted) return;
      final responseStatus = exception.response?.statusCode;
      // A received client-error response is a definitive rejection. A lost,
      // timed-out, or server-error response may follow a committed admission.
      // Never present that ambiguous outcome as either admitted or denied.
      const definitiveRejections = {400, 401, 403, 404, 422};
      setState(() => _status =
          definitiveRejections.contains(responseStatus) ? 'denied' : 'unknown');
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _scanEnabled = true;
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

  @override
  Widget build(BuildContext context) {
    final isOnline = ref.watch(connectivityProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Event Gate Admission')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if (!isOnline)
          const Text(
              'Online connection required. Admission is unavailable while offline.'),
        TextField(
            controller: _eventId,
            decoration: const InputDecoration(labelText: 'Event ID')),
        TextField(
            controller: _gateId,
            decoration: const InputDecoration(labelText: 'Gate ID')),
        const SizedBox(height: 12),
        if (!_scanEnabled)
          FilledButton(
              onPressed:
                  isOnline ? () => setState(() => _scanEnabled = true) : null,
              child: const Text('Start online scanner')),
        if (_scanEnabled && isOnline)
          SizedBox(
              height: 280,
              child:
                  MobileScanner(controller: _scanner, onDetect: _onDetected)),
        TextField(
            controller: _credential,
            decoration: const InputDecoration(
                labelText: 'Credential (manual fallback)')),
        FilledButton(
            onPressed: _busy || !isOnline
                ? null
                : () => _validate(_credential.text.trim()),
            child: Text(_busy ? 'Checking…' : 'Validate credential')),
        if (_status != null)
          Card(
              child: ListTile(
            leading:
                Icon(_status == 'admitted' ? Icons.check_circle : Icons.block),
            title: Text(_status == 'admitted'
                ? 'Admit attendee'
                : _status == 'already_admitted'
                    ? 'Already admitted'
                    : _status == 'unknown'
                        ? 'Admission not confirmed'
                        : 'Admission denied'),
            subtitle: _status == 'unknown'
                ? const Text(
                    'Reconnect and scan again to check the result. Do not admit until confirmed.')
                : _category == null
                    ? null
                    : Text('Category: $_category'),
          )),
        TextButton(onPressed: () => context.pop(), child: const Text('Close')),
      ]),
    );
  }
}

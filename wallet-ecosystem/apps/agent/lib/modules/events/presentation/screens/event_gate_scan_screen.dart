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
      if (mounted) {
        setState(() {
          _status = data?['status'] as String?;
          _category = data?['category'] as String?;
        });
      }
    } on DioException {
      if (mounted) setState(() => _status = 'denied');
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
                    : 'Admission denied'),
            subtitle: _category == null ? null : Text('Category: $_category'),
          )),
        TextButton(onPressed: () => context.pop(), child: const Text('Close')),
      ]),
    );
  }
}

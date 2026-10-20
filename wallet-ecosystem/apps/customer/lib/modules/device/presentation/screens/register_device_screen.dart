import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:uuid/uuid.dart';
import '../device_list_providers.dart';
import '../../data/services/device_attribute_collector.dart';

class RegisterDeviceScreen extends ConsumerStatefulWidget {
  const RegisterDeviceScreen({super.key});

  @override
  ConsumerState<RegisterDeviceScreen> createState() => _RegisterDeviceScreenState();
}

class _RegisterDeviceScreenState extends ConsumerState<RegisterDeviceScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  String _platform = 'android';
  bool _loading = false;
  String? _error;
  bool _registered = false;
  String? _challengeId;

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  Future<void> _register() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      const collector = DeviceAttributeCollector();
      final deviceInfo = await collector.collect();

      final repo = ref.read(deviceRepositoryProvider);

      final fingerprintHash = await repo.generateFingerprint(
        platform:   deviceInfo.platform,
        attributes: deviceInfo.attributes,
      );

      final result = await repo.registerDevice(
        deviceName:      _nameController.text.trim(),
        platform:        deviceInfo.platform,
        fingerprintHash: fingerprintHash,
        idempotencyKey:  const Uuid().v4(),
      );

      if (mounted) {
        setState(() {
          _registered  = true;
          _challengeId = result.challengeId;
          _platform    = deviceInfo.platform;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'Registration failed. Please try again.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_registered) {
      return Scaffold(
        appBar: AppBar(title: const Text('Verify Device')),
        body: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.mark_email_read_outlined, size: 48, color: Colors.green),
              const SizedBox(height: 16),
              Text(
                'Almost done!',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              const Text(
                'A verification code has been sent to your registered contact. Enter the code in the OTP verification screen to complete device registration.',
                style: TextStyle(fontSize: 14),
              ),
              if (_challengeId != null) ...[
                const SizedBox(height: 24),
                Semantics(
                  label: 'Continue to OTP verification',
                  child: FilledButton(
                    onPressed: () {
                      // Navigate to E01 OTP verification with _challengeId
                      Navigator.pop(context);
                    },
                    child: const Text('Continue to Verification'),
                  ),
                ),
              ],
            ],
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Register Device')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Semantics(
                      label: 'Device name field',
                      child: TextFormField(
                        controller: _nameController,
                        decoration: const InputDecoration(
                          labelText: 'Device name',
                          hintText: 'e.g. My Work Phone',
                          border: OutlineInputBorder(),
                        ),
                        maxLength: 100,
                        validator: (v) {
                          if (v == null || v.trim().isEmpty) return 'Device name is required.';
                          return null;
                        },
                      ),
                    ),
                    const SizedBox(height: 16),
                    Semantics(
                      label: 'Platform selection',
                      child: DropdownButtonFormField<String>(
                        initialValue: _platform,
                        decoration: const InputDecoration(
                          labelText: 'Platform',
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(value: 'android', child: Text('Android')),
                          DropdownMenuItem(value: 'ios', child: Text('iOS')),
                          DropdownMenuItem(value: 'web', child: Text('Web')),
                        ],
                        onChanged: (v) => setState(() => _platform = v ?? _platform),
                      ),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(
                        _error!,
                        style: const TextStyle(color: Colors.red, fontSize: 13),
                      ),
                    ],
                    const SizedBox(height: 24),
                    Semantics(
                      label: 'Register device button',
                      child: FilledButton(
                        onPressed: _register,
                        child: const Text('Register Device'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

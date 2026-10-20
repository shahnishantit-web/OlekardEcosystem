import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../shared/widgets/button.dart';
import '../../../../shared/widgets/loading.dart';
import '../../services/auth_api_service.dart';
import '../../state/auth_state_notifier.dart';

/// US-AUTH-034 — TOTP MFA enrollment screen.
/// Displays QR code URI and prompts for first TOTP code to confirm enrollment.
class MfaEnableScreen extends ConsumerStatefulWidget {
  const MfaEnableScreen({super.key});

  @override
  ConsumerState<MfaEnableScreen> createState() => _MfaEnableScreenState();
}

enum _EnrollPhase { loading, qr, confirm, backupCodes }

class _MfaEnableScreenState extends ConsumerState<MfaEnableScreen> {
  _EnrollPhase _phase = _EnrollPhase.loading;
  String? _totpUri;
  String? _enrollmentId;
  List<String>? _backupCodes;
  String? _error;
  final _codeController = TextEditingController();

  AuthApiService get _api => ref.read(authApiServiceProvider);

  @override
  void initState() {
    super.initState();
    _startEnrollment();
  }

  @override
  void dispose() {
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _startEnrollment() async {
    setState(() {
      _phase = _EnrollPhase.loading;
      _error = null;
    });
    try {
      final data = await _api.enrollTotp();
      setState(() {
        _totpUri = data.totpUri;
        _enrollmentId = data.enrollmentId;
        _phase = _EnrollPhase.qr;
      });
    } on AuthApiException catch (e) {
      setState(() => _error = e.message);
    }
  }

  Future<void> _confirm() async {
    if (_enrollmentId == null) return;
    final code = _codeController.text.trim();
    if (code.length != 6) {
      setState(() => _error = 'Enter the 6-digit code from your authenticator app.');
      return;
    }

    setState(() {
      _phase = _EnrollPhase.loading;
      _error = null;
    });

    try {
      final data = await _api.confirmTotpEnrollment(_enrollmentId!, code);
      setState(() {
        _backupCodes = data.backupCodes;
        _phase = _EnrollPhase.backupCodes;
      });
    } on AuthApiException catch (e) {
      setState(() {
        _error = e.message;
        _phase = _EnrollPhase.qr;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Enable Authenticator')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: switch (_phase) {
            _EnrollPhase.loading => const Center(
                child: LoadingWidget(message: 'Setting up authenticator…'),
              ),
            _EnrollPhase.qr => _QrPhase(
                totpUri: _totpUri!,
                codeController: _codeController,
                error: _error,
                onConfirm: _confirm,
                onCancel: () => Navigator.of(context).pop(),
              ),
            _EnrollPhase.confirm => const Center(
                child: LoadingWidget(message: 'Confirming…'),
              ),
            _EnrollPhase.backupCodes => _BackupCodesPhase(
                codes: _backupCodes!,
                onDone: () => Navigator.of(context).pop(true),
              ),
          },
        ),
      ),
    );
  }
}

class _QrPhase extends StatelessWidget {
  const _QrPhase({
    required this.totpUri,
    required this.codeController,
    required this.error,
    required this.onConfirm,
    required this.onCancel,
  });

  final String totpUri;
  final TextEditingController codeController;
  final String? error;
  final VoidCallback onConfirm;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Scan with your authenticator app',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 16),
        Text(
          'Scan the QR code below with Google Authenticator, Authy, or a '
          'compatible app.',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 24),
        // QR rendering requires a package (e.g., qr_flutter) — TBD-004 scope.
        // For now, display the URI as selectable text for manual entry.
        Semantics(
          label: 'TOTP URI — copy and paste into your authenticator app',
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
            ),
            child: SelectableText(
              totpUri,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
        ),
        const SizedBox(height: 32),
        Text(
          'Then enter the 6-digit code from the app:',
          style: Theme.of(context).textTheme.bodyMedium,
        ),
        const SizedBox(height: 12),
        TextField(
          controller: codeController,
          keyboardType: TextInputType.number,
          maxLength: 6,
          autofocus: false,
          decoration: InputDecoration(
            labelText: '6-digit code',
            border: const OutlineInputBorder(),
            errorText: error,
          ),
        ),
        const SizedBox(height: 16),
        AppButton(label: 'Enable', onPressed: onConfirm),
        const SizedBox(height: 8),
        TextButton(onPressed: onCancel, child: const Text('Cancel')),
      ],
    );
  }
}

class _BackupCodesPhase extends StatelessWidget {
  const _BackupCodesPhase({required this.codes, required this.onDone});

  final List<String> codes;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          header: true,
          child: Text(
            'Save your backup codes',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
        ),
        const SizedBox(height: 12),
        Semantics(
          liveRegion: true,
          child: Text(
            'Store these codes securely. Each code can be used once if you '
            'lose access to your authenticator app.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ),
        const SizedBox(height: 24),
        ...codes.map((code) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: SelectableText(
                code,
                style: Theme.of(context)
                    .textTheme
                    .bodyLarge
                    ?.copyWith(fontFamily: 'monospace'),
                semanticsLabel: 'Backup code: $code',
              ),
            )),
        const SizedBox(height: 32),
        AppButton(label: "I've saved these codes", onPressed: onDone),
      ],
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_payment/shared_payment.dart';
import '../../core/di/providers.dart';
import '../../core/router/app_router.dart';
import '../../shared/widgets/pin_pad_widget.dart';

class CustomerStepUpService implements StepUpService {
  final Ref _ref;

  CustomerStepUpService(this._ref);

  @override
  Future<String> acquireToken({required String scope}) async {
    final router = _ref.read(routerProvider);
    final context = router.routerDelegate.navigatorKey.currentContext;
    if (context == null) throw StateError('No navigator context available');

    // Show PIN dialog and await user entry.
    final pin = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PinSheet(scope: scope),
    );
    if (pin == null) throw Exception('Step-up cancelled');

    // Call step-up API — token is never stored or persisted; discarded after forwarding.
    final dio = _ref.read(apiClientProvider).dio;
    final challengeRes = await dio.post('/v1/auth/step-up/initiate', data: {
      'scope': scope,
      'operation_context': {'risk_required': true},
    });
    final challenge = challengeRes.data['data'] as Map<String, dynamic>;

    final verifyRes = await dio.post('/v1/auth/step-up/verify', data: {
      'step_up_token': challenge['step_up_token'],
      'method': 'pin',
      'credential': pin,
    });
    // never persisted; discarded after forwarding to notifier
    return verifyRes.data['data']['step_up_access_token'] as String;
  }
}

class _PinSheet extends StatefulWidget {
  final String scope;
  const _PinSheet({required this.scope});

  @override
  State<_PinSheet> createState() => _PinSheetState();
}

class _PinSheetState extends State<_PinSheet> {
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 24,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.security, size: 48, color: Colors.blue),
          const SizedBox(height: 12),
          const Text('Enter your PIN',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          const Text('Required to authorize this payment',
              textAlign: TextAlign.center),
          const SizedBox(height: 24),
          PinPadWidget(
            onCompleted: (pin) => Navigator.of(context).pop(pin),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Cancel'),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}

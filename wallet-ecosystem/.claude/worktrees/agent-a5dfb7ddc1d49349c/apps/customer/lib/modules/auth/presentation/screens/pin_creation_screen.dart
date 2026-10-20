import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../auth_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';

class PinCreationScreen extends ConsumerStatefulWidget {
  const PinCreationScreen({super.key});

  @override
  ConsumerState<PinCreationScreen> createState() => _PinCreationScreenState();
}

class _PinCreationScreenState extends ConsumerState<PinCreationScreen> {
  String? _firstPin;

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authProvider);
    final isLoading = auth.status == AuthStateEnum.authenticating;

    return Scaffold(
      appBar: AppBar(
        title: Text(_firstPin == null ? 'Create PIN' : 'Confirm PIN'),
      ),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: AppSpacing.xl),
            Text(
              _firstPin == null
                  ? 'Choose a 6-digit PIN for your wallet'
                  : 'Re-enter your PIN to confirm',
              style: AppTypography.body,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.xl),
            if (isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(
                length: 6,
                onCompleted: _onPinEntered,
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _onPinEntered(String pin) async {
    if (_firstPin == null) {
      setState(() => _firstPin = pin);
    } else {
      if (_firstPin == pin) {
        // Never log the PIN — only pass to service for hashing
        final pinHash = await ref.read(pinServiceProvider).hashPin(pin);
        if (mounted) {
          await ref.read(authProvider.notifier).setPin(pinHash);
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('PINs do not match — please try again')),
          );
          setState(() => _firstPin = null);
        }
      }
    }
  }
}

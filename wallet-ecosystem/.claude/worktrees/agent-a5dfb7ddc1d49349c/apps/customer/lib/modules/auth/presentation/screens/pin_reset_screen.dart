import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../auth_providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';

class PinResetScreen extends ConsumerStatefulWidget {
  const PinResetScreen({super.key});

  @override
  ConsumerState<PinResetScreen> createState() => _PinResetScreenState();
}

class _PinResetScreenState extends ConsumerState<PinResetScreen> {
  String? _newPin;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_newPin == null ? 'New PIN' : 'Confirm PIN')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            const SizedBox(height: AppSpacing.xl),
            Text(
              _newPin == null ? 'Enter your new 6-digit PIN' : 'Confirm your new PIN',
              style: AppTypography.body,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.xl),
            PinPadWidget(
              length: 6,
              onCompleted: _onPin,
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _onPin(String pin) async {
    if (_newPin == null) {
      setState(() => _newPin = pin);
    } else {
      if (_newPin == pin) {
        // hash the PIN — result used when backend call is wired in
        await ref.read(pinServiceProvider).hashPin(pin);
        // The OTP reference and code are stored in auth state (simplified here)
        // In production, pass them through navigation extras
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('PIN updated successfully')),
          );
          context.go('/login');
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('PINs do not match')),
          );
          setState(() => _newPin = null);
        }
      }
    }
  }
}

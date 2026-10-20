import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/security/pin_service.dart';
import 'package:wallet_agent/core/security/screenshot_guard.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/pin_pad_widget.dart';

enum _ChangePinStep { current, newPin, confirm }

class ChangePinScreen extends ConsumerStatefulWidget {
  const ChangePinScreen({super.key});

  @override
  ConsumerState<ChangePinScreen> createState() => _ChangePinScreenState();
}

class _ChangePinScreenState extends ConsumerState<ChangePinScreen> {
  _ChangePinStep _step = _ChangePinStep.current;
  String? _currentPinHash;
  String? _newPinHash;
  bool _isLoading = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    ScreenshotGuard.enable();
  }

  @override
  void dispose() {
    ScreenshotGuard.disable();
    super.dispose();
  }

  Future<void> _onCurrentPin(String pin) async {
    setState(() {
      _currentPinHash = PinService.hash(pin);
      _step = _ChangePinStep.newPin;
      _errorMessage = null;
    });
  }

  void _onNewPin(String pin) {
    setState(() {
      _newPinHash = PinService.hash(pin);
      _step = _ChangePinStep.confirm;
      _errorMessage = null;
    });
  }

  Future<void> _onConfirmPin(String pin) async {
    final confirmHash = PinService.hash(pin);
    if (_newPinHash != confirmHash) {
      setState(() {
        _errorMessage = 'PINs do not match. Please try again.';
        _step = _ChangePinStep.newPin;
        _newPinHash = null;
      });
      return;
    }
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });
    try {
      await createDio(null).post<void>(
        '/v1/agent/auth/change-pin',
        data: {
          'current_pin_hash': _currentPinHash,
          'new_pin_hash': _newPinHash,
        },
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('PIN changed successfully.')),
        );
        context.pop();
      }
    } on DioException catch (e) {
      setState(() {
        _errorMessage =
            (e.response?.data as Map<String, dynamic>?)?['message']
                    as String? ??
                'PIN change failed. Please try again.';
        _step = _ChangePinStep.current;
        _currentPinHash = null;
        _newPinHash = null;
      });
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Change PIN')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _stepTitle(),
              style: AppTypography.titleMedium,
            ),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(
                key: ValueKey(_step),
                onCompleted: _handlePin,
                errorText: _errorMessage,
              ),
          ],
        ),
      ),
    );
  }

  String _stepTitle() {
    switch (_step) {
      case _ChangePinStep.current:
        return 'Enter your current PIN';
      case _ChangePinStep.newPin:
        return 'Enter your new PIN';
      case _ChangePinStep.confirm:
        return 'Confirm your new PIN';
    }
  }

  Future<void> _handlePin(String pin) async {
    switch (_step) {
      case _ChangePinStep.current:
        await _onCurrentPin(pin);
        break;
      case _ChangePinStep.newPin:
        _onNewPin(pin);
        break;
      case _ChangePinStep.confirm:
        await _onConfirmPin(pin);
        break;
    }
  }
}

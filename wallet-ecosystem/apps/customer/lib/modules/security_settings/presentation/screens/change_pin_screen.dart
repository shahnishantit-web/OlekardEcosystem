import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/di/providers.dart';
import '../../../../core/security/screenshot_guard.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/pin_pad_widget.dart';

enum _Step { current, newPin, confirm }

class ChangePinScreen extends ConsumerStatefulWidget {
  const ChangePinScreen({super.key});

  @override
  ConsumerState<ChangePinScreen> createState() => _ChangePinScreenState();
}

class _ChangePinScreenState extends ConsumerState<ChangePinScreen> {
  _Step _step = _Step.current;
  String? _currentPin;
  String? _newPin;
  bool _isLoading = false;
  String? _error;
  final _guard = ScreenshotGuard();

  @override
  void initState() {
    super.initState();
    _guard.enable();
  }

  @override
  void dispose() {
    _guard.disable();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final (title, subtitle) = switch (_step) {
      _Step.current => ('Current PIN', 'Enter your current 6-digit PIN'),
      _Step.newPin => ('New PIN', 'Enter your new 6-digit PIN'),
      _Step.confirm => ('Confirm PIN', 'Re-enter your new PIN to confirm'),
    };

    return Scaffold(
      appBar: AppBar(title: const Text('Change PIN')),
      body: Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Column(
          children: [
            const SizedBox(height: AppSpacing.xl),
            Text(title,
                style: AppTypography.headline, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            Text(subtitle,
                style: AppTypography.body, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.xl),
            if (_isLoading)
              const Center(child: CircularProgressIndicator())
            else
              PinPadWidget(
                onCompleted: _handlePin,
              ),
            if (_error != null) ...[
              const SizedBox(height: AppSpacing.md),
              Text(_error!, style: const TextStyle(color: AppColors.error)),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _handlePin(String pin) async {
    switch (_step) {
      case _Step.current:
        setState(() {
          _currentPin = pin;
          _error = null;
          _step = _Step.newPin;
        });
      case _Step.newPin:
        setState(() {
          _newPin = pin;
          _error = null;
          _step = _Step.confirm;
        });
      case _Step.confirm:
        if (pin != _newPin) {
          setState(() {
            _error = 'PINs do not match. Please try again.';
            _step = _Step.newPin;
          });
          return;
        }
        await _submitChange();
    }
  }

  Future<void> _submitChange() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final dio = ref.read(apiClientProvider).dio;
      await dio.post('/v1/auth/pin/change', data: {
        'current_pin': _currentPin,
        'new_pin': _newPin,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('PIN changed successfully.')),
        );
        Navigator.of(context).pop();
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _currentPin = null;
          _newPin = null;
          _error = e.toString();
          _step = _Step.current;
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
}

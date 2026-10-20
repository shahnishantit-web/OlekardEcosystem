import 'package:flutter/material.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import '../../core/theme/design_tokens.dart';

class PinPadWidget extends StatefulWidget {
  final int length;
  final ValueChanged<String> onCompleted;

  const PinPadWidget({
    super.key,
    this.length = 6,
    required this.onCompleted,
  });

  @override
  State<PinPadWidget> createState() => _PinPadWidgetState();
}

class _PinPadWidgetState extends State<PinPadWidget> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'PIN entry',
      child: PinCodeTextField(
        appContext: context,
        length: widget.length,
        controller: _controller,
        obscureText: true,
        obscuringCharacter: '●',
        keyboardType: TextInputType.number,
        animationType: AnimationType.fade,
        pinTheme: PinTheme(
          shape: PinCodeFieldShape.box,
          borderRadius: BorderRadius.circular(8),
          fieldHeight: 50,
          fieldWidth: 44,
          activeFillColor: AppColors.surface,
          inactiveFillColor: AppColors.background,
          selectedFillColor: AppColors.surface,
          activeColor: AppColors.primary,
          inactiveColor: AppColors.outline,
          selectedColor: AppColors.primary,
        ),
        enableActiveFill: true,
        onCompleted: (val) {
          widget.onCompleted(val);
          _controller.clear();
        },
        onChanged: (_) {},
      ),
    );
  }
}

export 'package:design_system/design_system.dart' show OkPinPadWidget;

import 'package:flutter/material.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';

class PinPadWidget extends StatefulWidget {
  final void Function(String pin) onCompleted;
  final int length;
  final String? errorText;

  const PinPadWidget({
    super.key,
    required this.onCompleted,
    this.length = 6,
    this.errorText,
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
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        PinCodeTextField(
          appContext: context,
          length: widget.length,
          controller: _controller,
          obscureText: true,
          obscuringCharacter: '●',
          keyboardType: TextInputType.number,
          animationType: AnimationType.fade,
          pinTheme: PinTheme(
            shape: PinCodeFieldShape.box,
            borderRadius: BorderRadius.circular(AppSpacing.borderRadiusSm),
            fieldHeight: 52,
            fieldWidth: 44,
            activeFillColor: AppColors.surface,
            inactiveFillColor: AppColors.background,
            selectedFillColor: AppColors.surface,
            activeColor: AppColors.primary,
            inactiveColor: AppColors.divider,
            selectedColor: AppColors.primary,
          ),
          enableActiveFill: true,
          onCompleted: (value) {
            // Do NOT log the value here — PIN security requirement.
            widget.onCompleted(value);
          },
          onChanged: (_) {},
          beforeTextPaste: (_) => false,
        ),
        if (widget.errorText != null)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.sm),
            child: Text(widget.errorText!,
                style: const TextStyle(color: AppColors.error, fontSize: 13)),
          ),
      ],
    );
  }
}

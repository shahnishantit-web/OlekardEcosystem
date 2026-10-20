import 'package:flutter/material.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import '../ok_theme_extension.dart';
import '../tokens.dart';

class OkPinPadWidget extends StatefulWidget {
  final int pinLength;
  final void Function(String pin) onCompleted;

  const OkPinPadWidget({
    super.key,
    this.pinLength = 6,
    required this.onCompleted,
  });

  @override
  State<OkPinPadWidget> createState() => _OkPinPadWidgetState();
}

class _OkPinPadWidgetState extends State<OkPinPadWidget> {
  final _controller = TextEditingController();
  final _focusNode = FocusNode();

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>();
    final primaryColor = ok?.primary ?? OkTokens.colorGreenPrimary;
    final surfaceColor = ok?.appSurface ?? OkTokens.colorGreenSurface;
    final bgColor = ok?.appBackground ?? OkTokens.colorGreenBackground;
    final outlineColor = OkTokens.colorNeutralBorder;

    return Semantics(
      label: 'PIN entry',
      child: PinCodeTextField(
        appContext: context,
        length: widget.pinLength,
        controller: _controller,
        focusNode: _focusNode,
        // This State owns both objects and disposes them in dispose().
        autoDisposeControllers: false,
        obscureText: true,
        obscuringCharacter: '●',
        keyboardType: TextInputType.number,
        animationType: AnimationType.fade,
        pinTheme: PinTheme(
          shape: PinCodeFieldShape.box,
          borderRadius: BorderRadius.circular(8),
          fieldHeight: 50,
          fieldWidth: 44,
          activeFillColor: surfaceColor,
          inactiveFillColor: bgColor,
          selectedFillColor: surfaceColor,
          activeColor: primaryColor,
          inactiveColor: outlineColor,
          selectedColor: primaryColor,
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

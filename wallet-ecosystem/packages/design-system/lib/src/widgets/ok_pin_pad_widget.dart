import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Accessible six-digit PIN entry with a single native editable field.
///
/// The field is visually transparent and overlays the digit cells so browser
/// keyboard input reaches Flutter's standard TextField input bridge.
class OkPinPadWidget extends StatefulWidget {
  final ValueChanged<String> onCompleted;
  final int pinLength;
  final bool enabled;

  const OkPinPadWidget({
    super.key,
    required this.onCompleted,
    this.pinLength = 6,
    this.enabled = true,
  });

  @override
  State<OkPinPadWidget> createState() => _OkPinPadWidgetState();
}

class _OkPinPadWidgetState extends State<OkPinPadWidget> {
  late final TextEditingController _controller;
  late final FocusNode _focusNode;
  bool _completionSent = false;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController();
    _focusNode = FocusNode();
  }

  @override
  void didUpdateWidget(covariant OkPinPadWidget oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.pinLength != widget.pinLength &&
        _controller.text.length >= widget.pinLength) {
      _clearInput();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    setState(() {});
    if (value.length < widget.pinLength) {
      _completionSent = false;
      return;
    }
    if (_completionSent || value.length != widget.pinLength) return;
    _completionSent = true;
    widget.onCompleted(value);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _clearInput();
    });
  }

  void _clearInput() {
    _controller.clear();
    _completionSent = false;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final entered = _controller.text.length;
    return Semantics(
      label: 'PIN entry',
      textField: true,
      child: SizedBox(
        height: 64,
        child: Stack(
          fit: StackFit.expand,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(widget.pinLength, (index) {
                final active = index == entered && widget.enabled;
                return Container(
                  width: 42,
                  height: 52,
                  margin: const EdgeInsets.symmetric(horizontal: 5),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: theme.colorScheme.surface,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(
                      color: active
                          ? theme.colorScheme.primary
                          : theme.colorScheme.outlineVariant,
                      width: active ? 2 : 1,
                    ),
                  ),
                  child: index < entered
                      ? Text('●', style: theme.textTheme.headlineSmall)
                      : const SizedBox.shrink(),
                );
              }),
            ),
            TextField(
              controller: _controller,
              focusNode: _focusNode,
              enabled: widget.enabled,
              keyboardType: TextInputType.number,
              textInputAction: TextInputAction.done,
              obscureText: true,
              maxLength: widget.pinLength,
              inputFormatters: <TextInputFormatter>[
                FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(widget.pinLength),
              ],
              style: const TextStyle(color: Colors.transparent),
              cursorColor: Colors.transparent,
              decoration: const InputDecoration(
                counterText: '',
                border: InputBorder.none,
                focusedBorder: InputBorder.none,
                enabledBorder: InputBorder.none,
                contentPadding: EdgeInsets.zero,
              ),
              onChanged: _onChanged,
            ),
          ],
        ),
      ),
    );
  }
}

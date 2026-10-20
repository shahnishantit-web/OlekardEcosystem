import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:wallet_agent/main.dart';

void main() {
  testWidgets('AgentApp smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: AgentApp()));
  });
}

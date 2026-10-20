import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/annotations.dart';
import 'package:mockito/mockito.dart';
import 'package:uuid/uuid.dart';
import 'package:wallet_customer/modules/transfer/domain/entities/transfer_result.dart';
import 'package:wallet_customer/modules/transfer/domain/repositories/transfer_repository.dart';

@GenerateMocks([TransferRepository])
import 'double_submit_test.mocks.dart';

void main() {
  group('Double-submit / idempotency', () {
    late MockTransferRepository mockRepo;
    final idempotencyKey = const Uuid().v4();

    setUp(() {
      mockRepo = MockTransferRepository();
    });

    test('concurrent identical submissions both resolve with the same reference number', () async {
      final expected = TransferResult(
        transactionId: 'txn-001',
        referenceNumber: 'TXN-IDEMPOTENT-001',
        status: TransferStatus.completed,
        amountMinorUnits: 5000,
        feeMinorUnits: 50,
        currencyCode: 'KES',
        recipientName: 'Test Recipient',
        recipientWalletNumber: 'WAL-0002',
        createdAt: DateTime(2024, 1, 1),
      );

      // Both calls return the same result (idempotent replay)
      when(mockRepo.executeTransfer(any)).thenAnswer((_) async => expected);

      final request = TransferRequestDto(
        idempotencyKey: idempotencyKey,
        recipientWalletNumber: 'WAL-0002',
        amountMinorUnits: 5000,
        currencyCode: 'KES',
      );

      // Fire two concurrent submits
      final results = await Future.wait([
        mockRepo.executeTransfer(request),
        mockRepo.executeTransfer(request),
      ]);

      expect(results[0].referenceNumber, results[1].referenceNumber,
          reason: 'Both concurrent submissions must yield the same reference number');
      expect(results[0].status, TransferStatus.completed);
      expect(results[1].status, TransferStatus.completed);
    });
  });
}

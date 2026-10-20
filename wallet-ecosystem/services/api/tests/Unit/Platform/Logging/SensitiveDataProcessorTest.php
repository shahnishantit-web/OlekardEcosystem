<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Logging;

use App\Modules\Platform\Infrastructure\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    private SensitiveDataProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SensitiveDataProcessor();
    }

    /** @test */
    public function it_masks_password_in_context(): void
    {
        $record = $this->makeRecord(context: ['user' => 'alice', 'password' => 'secret123']);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['password']);
        $this->assertSame('alice', $result->context['user']);
    }

    /** @test */
    public function it_masks_pin_in_context(): void
    {
        $record = $this->makeRecord(context: ['pin' => '1234', 'amount' => 100]);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['pin']);
        $this->assertSame(100, $result->context['amount']);
    }

    /** @test */
    public function it_masks_token_in_extra(): void
    {
        $record = $this->makeRecord(extra: ['token' => 'eyJhbGciOiJIUzI1NiJ9', 'request_id' => 'abc']);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->extra['token']);
        $this->assertSame('abc', $result->extra['request_id']);
    }

    /** @test */
    public function it_masks_date_of_birth(): void
    {
        $record = $this->makeRecord(context: ['date_of_birth' => '1990-01-15']);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['date_of_birth']);
    }

    /** @test */
    public function it_masks_card_number(): void
    {
        $record = $this->makeRecord(context: ['card_number' => '4111111111111111']);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['card_number']);
    }

    /** @test */
    public function it_masks_national_id(): void
    {
        $record = $this->makeRecord(context: ['national_id' => 'A12345678']);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['national_id']);
    }

    /** @test */
    public function it_preserves_non_sensitive_fields(): void
    {
        $record = $this->makeRecord(context: [
            'user_id'    => 'uuid-123',
            'action'     => 'transfer',
            'amount'     => 500,
            'currency'   => 'USD',
        ]);

        $result = ($this->processor)($record);

        $this->assertSame('uuid-123', $result->context['user_id']);
        $this->assertSame('transfer', $result->context['action']);
        $this->assertSame(500, $result->context['amount']);
        $this->assertSame('USD', $result->context['currency']);
    }

    /** @test */
    public function it_masks_nested_sensitive_fields(): void
    {
        $record = $this->makeRecord(context: [
            'payload' => ['password' => 'nested-secret', 'name' => 'Bob'],
        ]);

        $result = ($this->processor)($record);

        $this->assertSame('***', $result->context['payload']['password']);
        $this->assertSame('Bob', $result->context['payload']['name']);
    }

    /** @test */
    public function it_returns_original_record_type(): void
    {
        $record = $this->makeRecord(context: ['foo' => 'bar']);

        $result = ($this->processor)($record);

        $this->assertInstanceOf(LogRecord::class, $result);
    }

    /** @test */
    public function it_handles_empty_context_and_extra(): void
    {
        $record = $this->makeRecord();

        $result = ($this->processor)($record);

        $this->assertSame([], $result->context);
        $this->assertSame([], $result->extra);
    }

    /** @param array<string, mixed> $context
     *  @param array<string, mixed> $extra */
    private function makeRecord(array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel:  'test',
            level:    Level::Info,
            message:  'test message',
            context:  $context,
            extra:    $extra,
        );
    }
}

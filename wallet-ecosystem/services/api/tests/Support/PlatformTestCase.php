<?php

declare(strict_types=1);

namespace Tests\Support;

abstract class PlatformTestCase extends TestCase
{
    use AssertAuditEvent;
    use AssertOutboxEvent;
}

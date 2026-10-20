<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Services\NotificationTemplateService;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Exceptions\TemplateNotFoundException;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationTemplateServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function createTemplate(string $key, bool $active = true): void
    {
        NotificationTemplateModel::create([
            'id'               => (string) Str::orderedUuid(),
            'key'              => $key,
            'category'         => NotificationCategory::Financial->value,
            'subject_template' => 'Hello {{name}}, amount: {{amount}}',
            'body_template'    => 'Dear {{name}}, you received {{amount}} {{currency}}.',
            'supported_channels' => ['in_app', 'push'],
            'variables'        => ['name', 'amount', 'currency'],
            'active'           => $active,
        ]);
    }

    public function test_render_substitutes_all_provided_variables(): void
    {
        $this->createTemplate('payment.test');

        $service = new NotificationTemplateService();
        $content = $service->render('payment.test', [
            'name'     => 'Alice',
            'amount'   => '500.00',
            'currency' => 'BWP',
        ]);

        $this->assertSame('Hello Alice, amount: 500.00', $content->renderedSubject);
        $this->assertStringContainsString('Alice', $content->renderedBody);
        $this->assertStringContainsString('500.00', $content->renderedBody);
        $this->assertStringContainsString('BWP', $content->renderedBody);
    }

    public function test_render_substitutes_unknown_with_placeholder_and_logs_warning(): void
    {
        $this->createTemplate('payment.test.missing');

        Log::shouldReceive('warning')
            ->times(3)
            ->with('notification.template.missing_variable', \Mockery::on(
                fn ($ctx) => isset($ctx['key']) && isset($ctx['variable'])
            ));

        $service = new NotificationTemplateService();
        $content = $service->render('payment.test.missing', ['name' => 'Bob']);

        $this->assertStringContainsString('[Unknown]', $content->renderedSubject);
    }

    public function test_render_throws_template_not_found_for_unknown_key(): void
    {
        $this->expectException(TemplateNotFoundException::class);

        $service = new NotificationTemplateService();
        $service->render('does.not.exist', []);
    }

    public function test_render_throws_template_not_found_for_inactive_template(): void
    {
        $this->createTemplate('inactive.template', active: false);

        $this->expectException(TemplateNotFoundException::class);

        $service = new NotificationTemplateService();
        $service->render('inactive.template', []);
    }
}

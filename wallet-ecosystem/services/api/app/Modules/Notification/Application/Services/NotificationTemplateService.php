<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\DTO\NotificationContent;
use App\Modules\Notification\Domain\Exceptions\TemplateNotFoundException;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use Illuminate\Support\Facades\Log;

class NotificationTemplateService
{
    public function render(string $key, array $variables): NotificationContent
    {
        $template = NotificationTemplateModel::where('key', $key)
            ->where('active', true)
            ->first();

        if ($template === null) {
            throw new TemplateNotFoundException("Notification template '{$key}' not found or inactive.");
        }

        $subject = $this->substitute($template->subject_template, $variables, $key);
        $body    = $this->substitute($template->body_template, $variables, $key);

        return new NotificationContent(
            renderedSubject:   $subject,
            renderedBody:      $body,
            supportedChannels: $template->supported_channels,
        );
    }

    private function substitute(string $template, array $variables, string $key): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($variables, $key): string {
            $varName = $matches[1];

            if (! array_key_exists($varName, $variables)) {
                Log::warning('notification.template.missing_variable', [
                    'key'      => $key,
                    'variable' => $varName,
                ]);

                return '[Unknown]';
            }

            return (string) $variables[$varName];
        }, $template) ?? $template;
    }
}

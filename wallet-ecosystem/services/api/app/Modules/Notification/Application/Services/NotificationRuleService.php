<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationChannel;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use Illuminate\Support\Facades\DB;

final class NotificationRuleService
{
    public function snapshot(string $key, NotificationCategory $category, bool $mandatory): ?array
    {
        if ($mandatory || $category->isMandatory()) {
            return null;
        }
        $template = NotificationTemplateModel::where('key', $key)->first();
        if (! $template) {
            return null;
        }
        $disabled = DB::connection('notification')->table('notification.admin_rules')->where('template_id', $template->id)->where('enabled', false)->pluck('channel')->all();

        return array_values(array_diff(array_map(fn (NotificationChannel $c) => $c->value, NotificationChannel::cases()), $disabled));
    }

    public function listing(): array
    {
        $rules = DB::connection('notification')->table('notification.admin_rules')->get()->keyBy(fn ($r) => $r->template_id.':'.$r->channel);
        $rows = [];
        foreach (NotificationTemplateModel::orderBy('key')->get() as $template) {
            foreach ($template->supported_channels as $channel) {
                $rule = $rules->get($template->id.':'.$channel);
                $mandatory = $template->category->isMandatory();
                $rows[] = ['template_id' => $template->id, 'event' => $template->key, 'channel' => $channel, 'mandatory' => $mandatory, 'enabled' => $mandatory || ! $rule || (bool) $rule->enabled, 'revision' => $rule?->revision ?? 0];
            }
        }

        return $rows;
    }
}

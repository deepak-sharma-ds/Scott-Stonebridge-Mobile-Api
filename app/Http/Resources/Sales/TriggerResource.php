<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Http\Resources\Base\BaseApiResource;
use App\Models\TriggerRule;
use Illuminate\Http\Request;

/**
 * Output shape for a matched proactive trigger.
 *
 *  {
 *    "has_trigger":  true,
 *    "trigger_id":   42,
 *    "trigger_type": "exit_intent",
 *    "page_type":    "product",
 *    "priority":     5,
 *    "delay_ms":     0,
 *    "message":      "Still deciding? Our team is here to help."
 *  }
 *
 * @mixin TriggerRule
 *
 * @property string|null $resolved_message Set by the controller after token
 *                                         interpolation; falls back to the
 *                                         raw template when null.
 */
class TriggerResource extends BaseApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'has_trigger' => true,
            'trigger_id' => $this->id,
            'trigger_type' => $this->trigger_type,
            'page_type' => $this->page_type,
            'priority' => $this->priority,
            // trigger_value carries seconds for time_on_page, percent for
            // scroll_depth; null for exit_intent + cart_abandonment.
            'delay_ms' => $this->trigger_type === TriggerRule::TYPE_TIME_ON_PAGE
                ? (int) ($this->trigger_value ?? 0) * 1000
                : 0,
            'scroll_percent' => $this->trigger_type === TriggerRule::TYPE_SCROLL_DEPTH
                ? (int) ($this->trigger_value ?? 0)
                : null,
            'message' => $this->resource->resolved_message ?? $this->message_template,
        ];
    }
}

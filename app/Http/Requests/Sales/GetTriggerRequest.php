<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Http\Requests\BaseApiRequest;
use App\Models\TriggerRule;

/**
 * Query payload for GET /api/v1/ai/triggers/{shop_domain}.
 *
 * shop_domain comes from the route segment, page_type + session_id from
 * query string. session_id is required so the dedupe flag can be applied
 * even though the rule itself does not need it to load.
 */
class GetTriggerRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'page_type' => [
                'required',
                'string',
                'in:'.implode(',', [
                    TriggerRule::PAGE_HOME,
                    TriggerRule::PAGE_PRODUCT,
                    TriggerRule::PAGE_CART,
                    TriggerRule::PAGE_COLLECTION,
                    TriggerRule::PAGE_ALL,
                ]),
            ],
            'session_id' => ['required', 'string', 'max:64'],
        ];
    }
}

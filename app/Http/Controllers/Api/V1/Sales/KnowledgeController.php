<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Sales\UpsertFaqRequest;
use App\Http\Resources\Sales\KnowledgeResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Merchant/internal knowledge management.
 *
 *   POST /api/v1/ai/knowledge/faq  -> upsertFaq
 *
 * Guarded by shopify.auth in the route definition.
 */
class KnowledgeController extends BaseApiController
{
    public function __construct(
        private readonly StoreKnowledgeServiceInterface $knowledge,
    ) {}

    public function upsertFaq(UpsertFaqRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $faq = $this->knowledge->upsertFaq(
                shopDomain: (string) $data['shop_domain'],
                question: (string) $data['question'],
                answer: (string) $data['answer'],
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'Failed to upsert FAQ.',
                [],
                ['error_code' => 'knowledge_faq_failed'],
                500,
            );
        }

        return $this->success('FAQ stored.', new KnowledgeResource($faq), statusCode: 201);
    }
}

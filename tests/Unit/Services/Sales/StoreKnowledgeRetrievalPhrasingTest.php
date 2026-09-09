<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Models\StoreKnowledge;
use App\Services\Sales\StoreKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Embeddings\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * Regression guard for ADR 0011 (raw-chunk embedding + retrieval weight
 * rebalance): the same underlying question, asked in different phrasings,
 * must retrieve the same knowledge row every time.
 *
 * Query embeddings here are NOT computed live — they were recorded once
 * from the real OpenAI text-embedding-3-small API for the exact fixture
 * texts below (see tests/Fixtures/knowledge-embeddings.json) and are
 * replayed via OpenAI::fake(). This validates real semantic behaviour
 * without a network call/cost on every test run. Re-record the fixture
 * (see docs/adr/0011-*.md) if the embedding model ever changes.
 */
class StoreKnowledgeRetrievalPhrasingTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC_FOUNDER = 'About Scott';

    private const TOPIC_SHIPPING = 'Shipping Policy';

    private const TOPIC_RETURNS = 'Return Policy';

    /** @var array<string, list<float>> */
    private static array $vectors;

    private StoreKnowledgeService $service;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = __DIR__.'/../../../Fixtures/knowledge-embeddings.json';
        self::$vectors = json_decode((string) file_get_contents($path), true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config(['sales.knowledge.retrieval.enable_semantic' => true]);

        $this->service = new StoreKnowledgeService(new MockShopifyClient);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->ofType(StoreKnowledge::TYPE_PAGE)->create([
            'title' => self::TOPIC_FOUNDER,
            'handle' => 'about-scott',
            'summary' => 'Scott founded the shop in 2015 and still oversees quality control himself.',
            'raw_content' => 'Scott Stonebridge founded this store in 2015 after years working as a master leather craftsman in Portland. He personally designs every bag pattern and still inspects the first run of every new product before it ships. The brand is named after him because quality control has always been a one-man show at heart, even as the team has grown.',
            'embedding' => self::$vectors[self::embedInput(self::TOPIC_FOUNDER)],
        ]);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->policy()->create([
            'title' => self::TOPIC_SHIPPING,
            'handle' => 'shipping-policy',
            'summary' => 'Orders ship in 2 days, arrive in 3-5 days domestically.',
            'raw_content' => 'Orders ship within 2 business days via standard courier. Delivery typically takes 3-5 business days domestically and 7-14 days internationally. Tracking numbers are emailed once the order ships.',
            'embedding' => self::$vectors[self::embedInput(self::TOPIC_SHIPPING)],
        ]);

        StoreKnowledge::factory()->forShop('demo.myshopify.com')->policy()->create([
            'title' => self::TOPIC_RETURNS,
            'handle' => 'return-policy',
            'summary' => 'Returns accepted within 30 days for a full refund.',
            'raw_content' => 'Items can be returned within 30 days of purchase for a full refund, provided they are unworn and in original packaging. Refunds are issued to the original payment method within 5-7 business days of receiving the return.',
            'embedding' => self::$vectors[self::embedInput(self::TOPIC_RETURNS)],
        ]);
    }

    private static function embedInput(string $topic): string
    {
        $bodies = [
            self::TOPIC_FOUNDER => 'Scott Stonebridge founded this store in 2015 after years working as a master leather craftsman in Portland. He personally designs every bag pattern and still inspects the first run of every new product before it ships. The brand is named after him because quality control has always been a one-man show at heart, even as the team has grown.',
            self::TOPIC_SHIPPING => 'Orders ship within 2 business days via standard courier. Delivery typically takes 3-5 business days domestically and 7-14 days internationally. Tracking numbers are emailed once the order ships.',
            self::TOPIC_RETURNS => 'Items can be returned within 30 days of purchase for a full refund, provided they are unworn and in original packaging. Refunds are issued to the original payment method within 5-7 business days of receiving the return.',
        ];

        return "{$topic}\n\n{$bodies[$topic]}";
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phrasingProvider(): array
    {
        return [
            'founder: who is scott' => ['who is scott', self::TOPIC_FOUNDER],
            'founder: who is scott stonebridge' => ['who is scott stonebridge', self::TOPIC_FOUNDER],
            'founder: tell me about the founder' => ['tell me about the founder of this store', self::TOPIC_FOUNDER],
            'shipping: how long does shipping take' => ['how long does shipping take', self::TOPIC_SHIPPING],
            'shipping: when will my order arrive' => ['when will my order arrive', self::TOPIC_SHIPPING],
            'shipping: delivery time' => ["what's your delivery time", self::TOPIC_SHIPPING],
            'returns: can i return an item' => ['can i return an item', self::TOPIC_RETURNS],
            'returns: refund policy' => ["what's your refund policy", self::TOPIC_RETURNS],
            'returns: send something back' => ['i want to send this back, how does that work', self::TOPIC_RETURNS],
        ];
    }

    #[DataProvider('phrasingProvider')]
    public function test_phrasing_of_the_same_question_retrieves_the_same_topic(string $query, string $expectedTopic): void
    {
        OpenAI::fake([
            CreateResponse::fake(['data' => [['embedding' => self::$vectors[$query]]]]),
        ]);

        $results = $this->service->searchForTool('demo.myshopify.com', $query);

        $this->assertNotEmpty($results, "Expected at least one knowledge row for query [{$query}].");
        $this->assertSame(
            $expectedTopic,
            $results[0]['title'],
            "Query [{$query}] should rank [{$expectedTopic}] first, got [{$results[0]['title']}]."
        );
    }
}

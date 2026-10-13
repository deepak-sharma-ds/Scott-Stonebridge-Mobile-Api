<?php

declare(strict_types=1);

namespace Tests\Feature\Services\EmailReading;

use App\Services\EmailReading\ComplianceGateService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Moderations;
use OpenAI\Responses\Moderations\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComplianceGateServiceTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function criticalQuestions(): array
    {
        return [
            'medical diagnosis' => ['Do I have cancer? The doctors are still running tests.', 'medical'],
            'prognosis' => ['Will my dad survive his operation next week?', 'medical'],
            'stop medication' => ['Should I stop taking my medication, I feel much better?', 'medical'],
            'prescription' => ['What dosage of tablets should I be on for my anxiety?', 'prescription'],
            'pregnancy outcome' => ['Will I miscarry again this time?', 'pregnancy'],
            'baby health' => ['Is my baby going to be healthy?', 'pregnancy'],
            'suicide' => ['I do not see the point and I want to die.', 'self-harm'],
            'self harm' => ['I keep thinking about hurting myself.', 'self-harm'],
            'criminal trial' => ['Will he be found guilty at his trial next month?', 'criminal'],
            'lottery' => ['Can you give me the winning numbers for the EuroMillions?', 'gambling'],
            'investment' => ['Should I invest in bitcoin this year?', 'gambling'],
        ];
    }

    #[DataProvider('criticalQuestions')]
    public function test_critical_questions_are_flagged_by_local_patterns(string $question, string $expectedCategory): void
    {
        OpenAI::fake();

        $reason = app(ComplianceGateService::class)->evaluate(['q1' => $question, '_raw' => ['Q1' => $question]]);

        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase($expectedCategory, (string) $reason);
        OpenAI::assertNotSent(Moderations::class);
    }

    public function test_a_clean_spiritual_question_passes_and_is_screened_by_moderation(): void
    {
        OpenAI::fake([$this->moderationReply()]);

        $reason = app(ComplianceGateService::class)->evaluate([
            'q1' => 'Will I find love and happiness in the coming year?',
            'q2' => 'Should I move closer to my family? I feel a bit stressed about the change.',
        ]);

        $this->assertNull($reason);
        OpenAI::assertSent(Moderations::class);
    }

    public function test_bereavement_questions_that_only_mention_loss_are_not_flagged(): void
    {
        OpenAI::fake([$this->moderationReply()]);

        $reason = app(ComplianceGateService::class)->evaluate([
            'q1' => 'My mum passed away last year after a long illness. Is she at peace and watching over us?',
        ]);

        $this->assertNull($reason);
    }

    public function test_a_moderation_self_harm_flag_holds_the_delivery_with_a_reason(): void
    {
        OpenAI::fake([$this->moderationReply(['self-harm/intent' => true])]);

        $reason = app(ComplianceGateService::class)->evaluate([
            'q1' => 'Everything feels hopeless and I cannot go on like this.',
        ]);

        $this->assertStringContainsString('self-harm/intent', (string) $reason);
    }

    public function test_moderation_categories_outside_the_configured_list_do_not_hold(): void
    {
        OpenAI::fake([$this->moderationReply(['harassment' => true])]);

        $reason = app(ComplianceGateService::class)->evaluate(['q1' => 'My neighbour keeps shouting at me, will it stop?']);

        $this->assertNull($reason);
    }

    public function test_a_moderation_outage_fails_open_and_relies_on_local_patterns(): void
    {
        OpenAI::fake([new \RuntimeException('Moderation down')]);

        $reason = app(ComplianceGateService::class)->evaluate(['q1' => 'Will I find love this year?']);

        $this->assertNull($reason);
    }

    public function test_empty_answers_pass_without_calling_moderation(): void
    {
        OpenAI::fake();

        $this->assertNull(app(ComplianceGateService::class)->evaluate(['q1' => '  ', '_raw' => ['Q1' => 'x']]));
        OpenAI::assertNotSent(Moderations::class);
    }

    /**
     * @param  array<string,bool>  $violated
     */
    private function moderationReply(array $violated = []): CreateResponse
    {
        $categories = [];
        $scores = [];
        foreach (['hate', 'hate/threatening', 'harassment', 'harassment/threatening', 'illicit', 'illicit/violent', 'self-harm', 'self-harm/intent', 'self-harm/instructions', 'sexual', 'sexual/minors', 'violence', 'violence/graphic'] as $category) {
            $categories[$category] = $violated[$category] ?? false;
            $scores[$category] = ($violated[$category] ?? false) ? 0.95 : 0.001;
        }

        return CreateResponse::fake([
            'results' => [[
                'categories' => $categories,
                'category_scores' => $scores,
                'flagged' => in_array(true, $categories, true),
            ]],
        ]);
    }
}

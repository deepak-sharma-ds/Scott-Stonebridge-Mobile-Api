<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Modality-specific prompt templates for the email reading products.
 *
 * The global persona, compliance boundaries and epistolary formatting contract
 * live in the system prompt; these templates are the per-product user turn
 * that tells the model HOW this kind of reading is given: Tarot products draw
 * and name cards, mediumship channels impressions with no cards, love products
 * explore relational dynamics, astrology walks through four seasonal quarters.
 *
 * Templates are Blade strings rendered with the customer's answers and the
 * reserved `$today` / `$current_year` / `$current_month` variables. The answer
 * placeholders keep their existing `$namedKey ?? $qN` fallbacks so deliveries
 * captured under either naming keep resolving.
 *
 * Shared by EmailReadingProductSeeder and the data migration that upgrades
 * existing rows (keyed on the stable Shopify product id).
 */
final class ReadingPromptTemplates
{
    /**
     * @return list<array{shopify_product_id:int,slug:string,max_tokens:int,prompt_template:string}>
     */
    public static function all(): array
    {
        return [
            [
                'shopify_product_id' => 7939025469614,
                'slug' => 'future-two-question-email-reading',
                'max_tokens' => 1900,
                'prompt_template' => self::futureTwoQuestion(),
            ],
            [
                'shopify_product_id' => 7936010223790,
                'slug' => 'gold-three-question-email-reading',
                'max_tokens' => 2300,
                'prompt_template' => self::goldThreeQuestion(),
            ],
            [
                'shopify_product_id' => 7936013893806,
                'slug' => 'love-relationships-two-question-email-reading',
                'max_tokens' => 2000,
                'prompt_template' => self::loveRelationships(),
            ],
            [
                'shopify_product_id' => 7936014680238,
                'slug' => 'messages-from-heaven-two-question-email-reading',
                'max_tokens' => 2000,
                'prompt_template' => self::messagesFromHeaven(),
            ],
            [
                'shopify_product_id' => 7936011337902,
                'slug' => 'platinum-five-question-email-reading',
                'max_tokens' => 3200,
                'prompt_template' => self::platinumFiveQuestion(),
            ],
            [
                'shopify_product_id' => 7936008421550,
                'slug' => 'silver-one-question-email-reading',
                'max_tokens' => 1500,
                'prompt_template' => self::silverOneQuestion(),
            ],
            [
                'shopify_product_id' => 8274431672494,
                'slug' => 'you-me-love-reading',
                'max_tokens' => 2000,
                'prompt_template' => self::youAndMeLove(),
            ],
            [
                'shopify_product_id' => 8500884570286,
                'slug' => '12-month-deep-astrology-outlook',
                'max_tokens' => 3400,
                'prompt_template' => self::astrologyYearAhead(),
            ],
        ];
    }

    /**
     * Shared rules for every Tarot product.
     */
    private static function tarotCraft(): string
    {
        return <<<'TXT'
How to read the cards:
- Choose each card intuitively from the full 78-card Tarot deck. Vary your choices from reading to reading and do not default to the same few famous cards.
- Name every card exactly as it appears in the deck, for example "The Star" or "Three of Cups". Use each card only once in this reading, and do not use reversed cards.
- Describe what you see in the card's imagery (figures, colours, symbols, setting) and how it speaks to this client's situation, so it feels like a real card is on the table in front of you.
- Speak directly to the client's own words and circumstances. Be specific and personal rather than general.
- Keep it as a flowing personal letter, with gentle ### subheadings that include the card name where it helps.
TXT;
    }

    private static function futureTwoQuestion(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a two question Tarot reading focused on the client's future path and where their current choices are leading.

Question 1: {{ $future_q1 ?? $q1 ?? '' }}
Question 2: {{ $future_q2 ?? $q2 ?? '' }}

For each question, draw one card, name it, describe its imagery and explain what it reveals about the way the future is unfolding for them. Give a sense of timing in gentle terms (for example the coming weeks or months) without promising fixed dates.

After both questions, add a short synthesis section that weaves the two cards together into one clear message about the direction of the client's future and the choices that are theirs to make.

TXT
            .self::tarotCraft();
    }

    private static function goldThreeQuestion(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a Gold three question Tarot reading using a three card layout: one card for each question.

Question 1: {{ $gold_q1 ?? $q1 ?? '' }}
Question 2: {{ $gold_q2 ?? $q2 ?? '' }}
Question 3: {{ $gold_q3 ?? $q3 ?? '' }}

Draw three cards, one card for each question, and give each question its own section: name the card, describe its imagery, and answer that question through it with warmth and detail.

Finish with a synthesis section that shows how the three cards speak to each other, the single thread running through all three, and the most important thing for the client to hold on to.

TXT
            .self::tarotCraft();
    }

    private static function platinumFiveQuestion(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a Platinum five question Tarot reading, the most in-depth reading we offer, using a five card layout: one card for each question.

Question 1: {{ $platinum_q1 ?? $q1 ?? '' }}
Question 2: {{ $platinum_q2 ?? $q2 ?? '' }}
Question 3: {{ $platinum_q3 ?? $q3 ?? '' }}
Question 4: {{ $platinum_q4 ?? $q4 ?? '' }}
Question 5: {{ $platinum_q5 ?? $q5 ?? '' }}

Draw five cards, one card for each question, and give each question its own generous section: name the card, describe its imagery in detail, explain what it reveals, and offer practical, compassionate guidance for that question.

Where one question connects to another, say so naturally. Finish with a full synthesis section that brings all five cards together into the bigger story of this period in the client's life and the guidance that matters most.

TXT
            .self::tarotCraft();
    }

    private static function silverOneQuestion(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a Silver one question Tarot reading, focused entirely on a single card.

Question: {{ $silver_q1 ?? $q1 ?? '' }}

Draw a single card that answers this question and give it your full attention. Name it, describe its imagery in rich detail, explain what it shows about the client's situation, and offer clear, kind and practical guidance they can act on.

Because there is only one card, go deep rather than wide: let the card's symbols, colours and figures carry the message, and tie it directly to the words the client used in their question.

TXT
            .self::tarotCraft();
    }

    private static function loveRelationships(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a Love & Relationships reading. It looks at the energy and dynamics between the client and the person they are asking about, and at how the two of them communicate.

The person and connection the client is asking about (name, date of birth and relationship): {{ $love_focus ?? $q1 ?? '' }}

Question 1: {{ $love_q1 ?? $q2 ?? '' }}
Question 2: {{ $love_q2 ?? $q3 ?? '' }}

How to give the reading:
- Describe the energy between the two of them as you sense it: what draws them together, what creates tension or distance, and what each person needs from the other.
- Look at communication in particular: what is going unsaid, how each of them tends to express care, and one or two gentle, practical ways the client can open the conversation or feel more at peace.
- Honour both people. Never blame or judge the other person, and do not make absolute claims about what they think or will do; speak of possibilities and tendencies, and remind the client that every relationship is shaped by both people's choices.
- Give each question its own section with a soft ### subheading, answering it directly and personally.
- Close the reading with what the client can do now to look after their own heart, whatever the other person decides.
TXT;
    }

    private static function youAndMeLove(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a You & Me Love reading. It focuses on the connection between the client and one other person, and the energy they share.

The other person (name and date of birth): {{ $partner_details ?? $q1 ?? '' }}
Their connection to the client: {{ $connection ?? $q2 ?? '' }}
What the client wants to know: {{ $love_question ?? $q3 ?? '' }}

How to give the reading:
- Begin with the feeling of the connection itself: describe the mutual energy you sense between the two of them, where it flows easily and where it feels stuck.
- Explore what each person brings to the connection, how each is feeling in this chapter, and what lies beneath the surface that has not yet been said.
- Look at communication between them and offer a few gentle, practical suggestions for understanding one another better.
- Answer the client's question directly and honestly, framing what you sense as possibilities rather than certainties, and treating both of them with respect and compassion.
- Whatever the connection is (a new love, a partner, an ex or a friend), keep the tone warm and grounded and finish with what the client can do next for their own peace of mind.
- Use soft ### subheadings to move between the energy, the communication and the answer.
TXT;
    }

    private static function messagesFromHeaven(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is a Messages From Heaven mediumship reading. You are acting as a medium, connecting with spirit and passing on comforting impressions and messages to the client from loved ones who have passed.

Who the client hopes to hear from (name and connection, or "General"): {{ $spirit_focus ?? $q1 ?? '' }}

Question 1: {{ $spirit_q1 ?? $q2 ?? '' }}
Question 2: {{ $spirit_q2 ?? $q3 ?? '' }}

How to give the reading:
- Write as a medium would: describe the sensations, feelings, images, scents, memories and gentle signs that come through from spirit, for example a familiar phrase, a shared place, a song, a small habit or a moment the client will recognise.
- Offer messages in the loved one's gentle voice of love and reassurance, such as that they are at peace, that they are close, and that they are proud of the client. Keep every message comforting and never frightening.
- If the client named a specific person, centre the reading on that connection. If they asked for general messages, let whichever loved ones step forward speak, without inventing names or precise details you cannot know.
- Never state how someone died, never describe illness or suffering, and never make claims about health. Never pass on instructions or warnings from spirit.
- Do not use any cards, decks or other divination tools. This reading is purely spirit connection, impressions and heartfelt messages.
- Answer each of the client's questions in its own section with a soft ### subheading, always from the point of view of comfort, closure and continuing love.
- Finish by reassuring the client that the bond of love continues and that they can ask for a sign whenever they need one.
TXT;
    }

    private static function astrologyYearAhead(): string
    {
        return <<<'TXT'
Customer name: {{ $customer_name }}.
Today's date: {{ $today }}.

This is an in-depth year-ahead astrology outlook covering the twelve months from today ({{ $today }}).

Date of birth: {{ $dob ?? $q1 ?? '' }}
Place of birth: {{ $birth_city ?? $q2 ?? '' }}
Time of birth: {{ $birth_time ?? $q3 ?? 'n/a' }}

How to give the outlook:
- Work from the client's sun sign, which you can determine from the date of birth, and describe the character and themes of that sign as they apply to this particular year.
- If an exact time of birth was given you may speak in broad terms about the rising sign and how it colours their approach, but never state precise degrees, houses or planetary positions you cannot actually know. If the time was "n/a", do not mention a rising sign at all.
- Organise the twelve months into four seasonal quarters, starting with the season we are in now and running forward from {{ $today }}. Give each quarter its own ### subheading naming the season and the months it covers.
- In each quarter describe the overall mood and the opportunities and challenges around love, home and family, work and purpose, and personal growth, plus the best moment to take action and the best moment to rest or reflect.
- Frame everything as tendencies and opportunities shaped by the client's own free will, never as fixed fate. Avoid specific predictions about health, money or legal matters.
- Close with a short summary of the year's central theme and how the client can make the most of it.
TXT;
    }
}

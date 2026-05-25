<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\Streaming;

use App\Services\AI\Streaming\ChunkEmitter;
use Tests\TestCase;

class ChunkEmitterTest extends TestCase
{
    public function test_emit_writes_sse_data_line_with_type_discriminator(): void
    {
        $emitter = new ChunkEmitter;
        $output = $this->capture(fn () => $emitter->emit('products', ['products' => [['id' => 'p1']]]));

        $this->assertSame("data: {\"type\":\"products\",\"products\":[{\"id\":\"p1\"}]}\n\n", $output);
    }

    public function test_emit_text_wraps_delta_into_text_chunk(): void
    {
        $emitter = new ChunkEmitter;
        $output = $this->capture(fn () => $emitter->emitText('Hello.'));

        $this->assertSame("data: {\"type\":\"text\",\"content\":\"Hello.\"}\n\n", $output);
    }

    public function test_emit_text_drops_empty_delta(): void
    {
        $emitter = new ChunkEmitter;
        $output = $this->capture(fn () => $emitter->emitText(''));

        $this->assertSame('', $output);
    }

    public function test_emit_done_writes_terminal_done_event_and_done_sentinel(): void
    {
        $emitter = new ChunkEmitter;
        $output = $this->capture(fn () => $emitter->emitDone());

        $this->assertSame("data: {\"type\":\"done\",\"done\":true}\n\ndata: [DONE]\n\n", $output);
    }

    public function test_emit_keepalive_uses_sse_comment(): void
    {
        $emitter = new ChunkEmitter;
        $output = $this->capture(fn () => $emitter->emitKeepalive());

        $this->assertSame(": keepalive\n\n", $output);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        try {
            $callback();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}

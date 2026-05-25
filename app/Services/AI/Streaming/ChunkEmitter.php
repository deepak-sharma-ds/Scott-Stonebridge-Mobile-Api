<?php

declare(strict_types=1);

namespace App\Services\AI\Streaming;

/**
 * Writes SSE chunks shaped `{type: <name>, ...}` straight to the output
 * buffer. The streaming controller wraps the AI turn in an ob_start so this
 * emitter can be tested by capturing output.
 */
class ChunkEmitter
{
    /**
     * Push one SSE event with a `type` discriminator + payload merged in.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(string $type, array $data = []): void
    {
        $payload = ['type' => $type] + $data;
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        echo 'data: '.$encoded."\n\n";
        $this->flush();
    }

    public function emitText(string $delta): void
    {
        if ($delta === '') {
            return;
        }
        $this->emit('text', ['content' => $delta]);
    }

    public function emitDone(): void
    {
        $this->emit('done', ['done' => true]);
        echo "data: [DONE]\n\n";
        $this->flush();
    }

    public function emitKeepalive(): void
    {
        echo ": keepalive\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        // In unit tests the caller wraps emit() in its own ob_start to capture
        // output. Calling ob_flush() there would pop the test buffer one layer
        // up — past PHPUnit's stdout buffer — so the assertions never see it.
        if (app()->runningUnitTests()) {
            return;
        }

        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}

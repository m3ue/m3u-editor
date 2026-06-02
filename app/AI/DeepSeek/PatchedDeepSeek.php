<?php

declare(strict_types=1);

namespace App\AI\DeepSeek;

use Generator;
use Prism\Prism\Providers\DeepSeek\DeepSeek;
use Prism\Prism\Text\Request as TextRequest;

/**
 * Overrides Prism's DeepSeek provider to use PatchedStream, which correctly
 * passes reasoning_content back to the API during tool-call multi-turn requests.
 */
class PatchedDeepSeek extends DeepSeek
{
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new PatchedStream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }
}

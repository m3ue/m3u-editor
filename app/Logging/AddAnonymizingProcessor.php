<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class AddAnonymizingProcessor
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(app(AnonymizingProcessor::class));
    }
}

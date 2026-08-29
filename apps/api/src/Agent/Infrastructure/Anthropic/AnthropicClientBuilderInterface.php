<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

use Anthropic\Client;

interface AnthropicClientBuilderInterface
{
    /**
     * @param array{maxRetries: int, timeout: float} $requestOptions
     */
    public function build(string $apiKey, array $requestOptions): Client;
}

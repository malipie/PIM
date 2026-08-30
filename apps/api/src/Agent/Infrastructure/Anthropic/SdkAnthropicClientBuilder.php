<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

use Anthropic\Client;

final readonly class SdkAnthropicClientBuilder implements AnthropicClientBuilderInterface
{
    /**
     * @param array{maxRetries: int, timeout: float} $requestOptions
     */
    public function build(string $apiKey, array $requestOptions): Client
    {
        return new Client(
            apiKey: $apiKey,
            requestOptions: $requestOptions,
        );
    }
}

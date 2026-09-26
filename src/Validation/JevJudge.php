<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

use JsonException;
use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Http\Client\HttpClientException;

/**
 * {@see Judge} backed by TypeSafe's Jev model (a single `noul` question per
 * call against `POST /v1/systemone`).
 *
 * Any failure — transport error, non-2xx, unexpected body — throws
 * {@see JudgeException} instead of silently passing or failing the field:
 * whether an outage should block a form is the app's call, not ours.
 */
final readonly class JevJudge implements Judge
{
    public const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    public function __construct(
        private HttpClient $http,
        private string $apiKey,
        private string $model = 'jev-latest',
    ) {}

    public function probability(string $condition, mixed $state): float
    {
        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ], \json_encode([
                'state' => $state,
                'model' => $this->model,
                'questions' => ['q' => ['type' => 'noul', 'instructions' => $condition]],
            ], \JSON_THROW_ON_ERROR));

            if (!$response->ok()) {
                throw new JudgeException(\sprintf('TypeSafe API returned HTTP %d.', $response->status));
            }

            $body = $response->json();
        } catch (HttpClientException|JsonException $e) {
            throw new JudgeException('TypeSafe API request failed: ' . $e->getMessage(), 0, $e);
        }

        $p = \is_array($body)
            && \is_array($body['answers'] ?? null)
            && \is_array($body['answers']['q'] ?? null)
            ? ($body['answers']['q']['noul'] ?? null)
            : null;

        if (!\is_float($p) && !\is_int($p)) {
            throw new JudgeException('TypeSafe API returned an unexpected body.');
        }

        return (float) $p;
    }
}

<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Client\HttpClientException;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Tests\Http\Client\FakeHttpClient;
use Polidog\Relayer\Validation\JevJudge;
use Polidog\Relayer\Validation\Judge;
use Polidog\Relayer\Validation\JudgeException;
use Polidog\Relayer\Validation\Validator;

final class JevJudgeTest extends TestCase
{
    public function testSendsNoulQuestionAndReturnsProbability(): void
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(200, [], '{"answers":{"q":{"type":"noul","noul":0.93}}}');

        $p = (new JevJudge($http, 'secret'))->probability('`value` is spam', ['value' => 'buy now']);

        self::assertSame(0.93, $p);
        self::assertSame('POST', $http->lastMethod);
        self::assertSame(JevJudge::ENDPOINT, $http->lastUrl);
        self::assertSame('Bearer secret', $http->lastHeaders['Authorization']);
        self::assertSame(
            '{"state":{"value":"buy now"},"model":"jev-latest","questions":{"q":{"type":"noul","instructions":"`value` is spam"}}}',
            $http->lastBody,
        );
    }

    public function testNon2xxThrows(): void
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(401, [], '{}');

        $this->expectException(JudgeException::class);
        (new JevJudge($http, 'bad'))->probability('x', 'y');
    }

    public function testUnexpectedBodyThrows(): void
    {
        $http = new FakeHttpClient();
        $http->response = new HttpResponse(200, [], '{"answers":{}}');

        $this->expectException(JudgeException::class);
        (new JevJudge($http, 'k'))->probability('x', 'y');
    }

    public function testTransportFailureThrows(): void
    {
        $http = new FakeHttpClient();
        $http->throw = new HttpClientException('timeout');

        $this->expectException(JudgeException::class);
        (new JevJudge($http, 'k'))->probability('x', 'y');
    }

    public function testSatisfiesUsesThresholdAndSkipsJudgeAfterEarlierFailure(): void
    {
        $judge = new class implements Judge {
            public int $calls = 0;

            public function probability(string $condition, mixed $state): float
            {
                ++$this->calls;

                return \is_array($state) && 'good' === $state['value'] ? 0.9 : 0.2;
            }
        };

        $schema = Validator::string()->min(3)->satisfies($judge, '`value` is fine', 'Rejected.', 0.8);

        self::assertTrue($schema->safeParse('good')->success);
        self::assertSame(['' => 'Rejected.'], $schema->safeParse('bad!')->errors);
        self::assertFalse($schema->safeParse('no')->success); // min(3) fails first
        self::assertSame(2, $judge->calls);
    }
}

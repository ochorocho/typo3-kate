<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Tests\Unit\Http;

use Ochorocho\Mercure\Configuration\MercureConfiguration;
use Ochorocho\Mercure\Http\MercureMiddleware;
use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\Mercure\Hub\HubFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

final class MercureMiddlewareTest extends TestCase
{
    private const string PUBLIC_URL = 'https://www.example.com/.well-known/mercure';
    private const string SUBSCRIBER_KEY = 'subscriber-key-of-at-least-32-bytes-length';

    private MercureMiddleware $subject;

    protected function setUp(): void
    {
        $hub = HubFactory::create(new MercureConfiguration(
            hubUrl: 'http://typo3-mercure/.well-known/mercure',
            publicUrl: self::PUBLIC_URL,
            publisherJwtKey: 'publisher-key-of-at-least-32-bytes-length',
            subscriberJwtKey: self::SUBSCRIBER_KEY,
        ));
        $this->subject = new MercureMiddleware($hub, new Authorization(new HubRegistry($hub)));
    }

    /**
     * @param \Closure(SubscriberGrants): void $render what the page does with Mercure
     */
    private function handle(\Closure $render): ResponseInterface
    {
        $handler = new class ($render) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $render) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->render)($request->getAttribute(SubscriberGrants::ATTRIBUTE));
                return new Response();
            }
        };
        return $this->subject->process(new ServerRequest('https://www.example.com/page'), $handler);
    }

    #[Test]
    public function pagesWithoutMercureAreLeftAlone(): void
    {
        $response = $this->handle(static fn() => null);

        self::assertFalse($response->hasHeader('Link'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function publicSubscriptionsGetTheDiscoveryLinkOnly(): void
    {
        $response = $this->handle(static fn(SubscriberGrants $grants) => $grants->markUsed());

        self::assertSame('<' . self::PUBLIC_URL . '>; rel="mercure"', $response->getHeaderLine('Link'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    #[Test]
    public function privateTopicsGetASubscriberCookie(): void
    {
        $response = $this->handle(static fn(SubscriberGrants $grants) => $grants->authorize('https://example.com/books/1'));

        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith('__Secure-mercure_access_token=', $cookie);
        self::assertStringContainsString('path=/.well-known/mercure', $cookie);
        self::assertStringContainsString('httponly', $cookie);

        preg_match('/=([^;]+);/', $cookie, $matches);
        [$header, $payload, $signature] = explode('.', $matches[1]);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertSame('at+jwt', json_decode(base64_decode(strtr($header, '-_', '+/')), true)['typ']);
        self::assertSame('https://www.example.com', $claims['iss']);
        self::assertSame(self::PUBLIC_URL, is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
        self::assertSame('anonymous', $claims['sub']);
        self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
        self::assertSame([['match' => 'https://example.com/books/1']], $claims['authorization_details'][0]['topics']);
        // signed with the subscriber key, not the publisher key
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, self::SUBSCRIBER_KEY, true)), '+/', '-_'), '=');
        self::assertSame($expected, $signature);
    }
}

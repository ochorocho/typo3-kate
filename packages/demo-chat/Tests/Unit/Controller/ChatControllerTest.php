<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\Tests\Unit\Controller;

use Ochorocho\KateDemoChat\Controller\ChatController;
use Ochorocho\KateDemoChat\Tests\Unit\Fixtures\FakeHub;
use Ochorocho\Mercure\Http\SubscriberGrants;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

final class ChatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->logIn(['uid' => 3, 'username' => 'jochen', 'realName' => '']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    /** @param array<string, mixed>|null $record */
    private function logIn(?array $record): void
    {
        if ($record === null) {
            unset($GLOBALS['BE_USER']);
            return;
        }
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->user = $record;
        $GLOBALS['BE_USER'] = $user;
    }

    private function configuredHub(): FakeHub
    {
        return new FakeHub(factory: $this->createStub(TokenFactoryInterface::class));
    }

    private function controller(FakeHub $hub, ?NullLogger $logger = null): ChatController
    {
        return new ChatController($hub, $logger ?? new NullLogger());
    }

    private function send(ChatController $controller, string $body): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/kate-demo-chat/send', 'POST'))
            ->withBody((new StreamFactory())->createStream($body));
        return $controller->sendAction($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true);
    }

    #[Test]
    public function subscribeGrantsTheTopicAndReturnsHubAndUser(): void
    {
        $grants = new SubscriberGrants();
        $request = (new ServerRequest('https://example.com/typo3/ajax/kate-demo-chat/subscribe'))
            ->withAttribute(SubscriberGrants::ATTRIBUTE, $grants);

        $response = $this->controller($this->configuredHub())->subscribeAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([ChatController::TOPIC], $grants->getTopics());
        self::assertSame([
            'hub' => 'https://example.com/.well-known/mercure?match=' . rawurlencode(ChatController::TOPIC),
            'user' => ['id' => 3, 'name' => 'jochen'],
        ], $this->json($response));
    }

    #[Test]
    public function subscribeNamesTheUserByRealNameWhenSet(): void
    {
        $this->logIn(['uid' => 3, 'username' => 'jochen', 'realName' => 'Jochen R']);
        $request = (new ServerRequest('https://example.com/'))->withAttribute(SubscriberGrants::ATTRIBUTE, new SubscriberGrants());

        $response = $this->controller($this->configuredHub())->subscribeAction($request);

        self::assertSame('Jochen R', $this->json($response)['user']['name']);
    }

    #[Test]
    public function subscribeWithoutConfiguredHubAnswers503(): void
    {
        $request = (new ServerRequest('https://example.com/'))->withAttribute(SubscriberGrants::ATTRIBUTE, new SubscriberGrants());

        // no token factory: EXT:mercure can't sign a subscriber cookie
        self::assertSame(503, $this->controller(new FakeHub())->subscribeAction($request)->getStatusCode());
    }

    #[Test]
    public function subscribeWithoutLoginAnswers401(): void
    {
        $this->logIn(null);

        self::assertSame(401, $this->controller($this->configuredHub())->subscribeAction(new ServerRequest('https://example.com/'))->getStatusCode());
    }

    #[Test]
    public function sendPublishesAPrivateUpdateAsTheLoggedInUser(): void
    {
        $hub = $this->configuredHub();

        // a user in the body is ignored: the login says who wrote it
        $response = $this->send($this->controller($hub), '{"text": "Hi there", "user": {"id": 99, "name": "someone else"}}');

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $hub->published);
        $update = $hub->published[0];
        self::assertSame([ChatController::TOPIC], $update->getTopics());
        self::assertTrue($update->isPrivate());
        $data = json_decode($update->getData(), true);
        self::assertSame(['id' => 3, 'name' => 'jochen'], $data['user']);
        self::assertSame('Hi there', $data['text']);
        self::assertSame($data['id'], $this->json($response)['id']);
    }

    #[Test]
    public function sendWithoutTextAnswers400(): void
    {
        $hub = $this->configuredHub();

        foreach (['', 'not json', '{"text": 5}', '{"text": "   "}'] as $body) {
            self::assertSame(400, $this->send($this->controller($hub), $body)->getStatusCode(), $body);
        }
        self::assertSame([], $hub->published);
    }

    #[Test]
    public function sendWhenTheHubFailsAnswers502(): void
    {
        $hub = $this->configuredHub();
        $hub->failWith = new \RuntimeException('hub down');

        self::assertSame(502, $this->send($this->controller($hub), '{"text": "Hi"}')->getStatusCode());
    }

    #[Test]
    public function sendWithoutLoginAnswers401(): void
    {
        $this->logIn(null);
        $hub = $this->configuredHub();

        self::assertSame(401, $this->send($this->controller($hub), '{"text": "Hi"}')->getStatusCode());
        self::assertSame([], $hub->published);
    }
}

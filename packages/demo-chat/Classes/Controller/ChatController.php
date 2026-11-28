<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\Controller;

use Ochorocho\KateDemoChat\Chat\ChatMessage;
use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\Mercure\Http\SubscriptionUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The two AJAX routes of the chat (Configuration/Backend/AjaxRoutes.php).
 * Backend routes need a backend login and the route's CSRF token. Messages
 * are private Mercure updates: only browsers with the subscriber cookie,
 * which subscribe hands out, receive them.
 */
#[AsController]
final readonly class ChatController
{
    public const string TOPIC = 'urn:typo3-kate:demo-chat';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {}

    /**
     * The hub URL to subscribe to, plus the subscriber cookie (set by
     * EXT:mercure's middleware for the granted topic).
     */
    public function subscribeAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendUser();
        if ($user === null) {
            return $this->error('Not logged in.', 401);
        }
        if ($this->hub->getPublicUrl() === '' || $this->hub->getFactory() === null) {
            return $this->error('The Mercure hub is not configured.', 503);
        }
        $grants = $request->getAttribute(SubscriberGrants::ATTRIBUTE);
        if (!$grants instanceof SubscriberGrants) {
            return $this->error('EXT:mercure\'s middleware did not run.', 503);
        }
        $grants->authorize(self::TOPIC);
        return new JsonResponse([
            'hub' => SubscriptionUrl::build($this->hub, [self::TOPIC]),
            'user' => ['id' => (int)$user->user['uid'], 'name' => $this->userName($user)],
        ]);
    }

    /**
     * Publishes {"text": "…"} as the logged-in user.
     */
    public function sendAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->backendUser();
        if ($user === null) {
            return $this->error('Not logged in.', 401);
        }
        $body = json_decode((string)$request->getBody(), true);
        $text = is_array($body) ? ($body['text'] ?? null) : null;
        if (!is_string($text)) {
            return $this->error('Send JSON with a "text" string.', 400);
        }
        try {
            $message = ChatMessage::create($text, (int)$user->user['uid'], $this->userName($user), new \DateTimeImmutable());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }
        try {
            $this->hub->publish(new Update(self::TOPIC, $message->toJson(), private: true));
        } catch (\Throwable $e) {
            $this->logger->error('Demo chat: publishing to the Mercure hub failed', ['exception' => $e]);
            return $this->error('The Mercure hub did not take the message.', 502);
        }
        return new JsonResponse(['id' => $message->id], 201);
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication && isset($user->user['uid']) ? $user : null;
    }

    private function userName(BackendUserAuthentication $user): string
    {
        $realName = trim((string)($user->user['realName'] ?? ''));
        return $realName !== '' ? $realName : (string)$user->user['username'];
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => $message], $status);
    }
}

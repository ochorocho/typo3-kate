<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;

/**
 * For responses that use Mercure (see SubscriberGrants), adds the discovery
 * Link header and, for private topics, the subscriber cookie: an HttpOnly
 * JWT that the browser sends along with the EventSource connection.
 */
final readonly class MercureMiddleware implements MiddlewareInterface
{
    public function __construct(
        private HubInterface $hub,
        private Authorization $authorization,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $grants = new SubscriberGrants();
        $response = $handler->handle($request->withAttribute(SubscriberGrants::ATTRIBUTE, $grants));

        $publicUrl = $this->hub->getPublicUrl();
        if (!$grants->isUsed() || $publicUrl === '') {
            return $response;
        }
        $response = $response->withAddedHeader('Link', sprintf('<%s>; rel="mercure"', $publicUrl));

        $topics = $grants->getTopics();
        if ($topics === [] || $this->hub->getFactory() === null) {
            return $response;
        }
        $cookie = $this->authorization->createCookie(
            Request::create((string)$request->getUri()),
            [new Grant([Grant::ACTION_SUBSCRIBE], $topics)],
            null,
            ['sub' => $this->subject($request)],
        );
        return $response->withAddedHeader('Set-Cookie', (string)$cookie);
    }

    /**
     * Who the token is for, so the hub's logs and subscription events can tell
     * subscribers apart.
     */
    private function subject(ServerRequestInterface $request): string
    {
        $frontendUser = $request->getAttribute('frontend.user');
        if ($frontendUser instanceof AbstractUserAuthentication && isset($frontendUser->user['uid'])) {
            return 'fe_users:' . $frontendUser->user['uid'];
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof AbstractUserAuthentication && isset($backendUser->user['uid'])) {
            return 'be_users:' . $backendUser->user['uid'];
        }
        return 'anonymous';
    }
}

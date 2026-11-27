<?php

declare(strict_types=1);

namespace Ochorocho\Mercure\ViewHelpers;

use Ochorocho\Mercure\Http\SubscriberGrants;
use Ochorocho\Mercure\Http\SubscriptionUrl;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mercure\HubInterface;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The hub URL to subscribe to, for an EventSource in the browser.
 *
 * Public updates:
 *   <div data-mercure-url="{mercure:url(topics: {0: 'https://example.com/books/1'})}">
 *
 * Private updates (authorize="1") also get a subscriber cookie for these
 * topics. The cookie belongs to the visitor, so the page is not cached:
 *   const source = new EventSource('{mercure:url(topics: {0: topic}, authorize: 1)}', {withCredentials: true});
 *
 * Global namespace "mercure", no import needed.
 */
final class UrlViewHelper extends AbstractViewHelper
{
    public function __construct(private readonly HubInterface $hub) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('topics', 'array', 'Topics to subscribe to (exact match). Empty: the bare hub URL', false, []);
        $this->registerArgument('authorize', 'bool', 'Allow this visitor to receive private updates of these topics', false, false);
        $this->registerArgument('lastEventId', 'string', 'Resume after this event', false, '');
    }

    public function render(): string
    {
        $topics = array_values(array_map(strval(...), (array)$this->arguments['topics']));
        $request = $this->renderingContext->hasAttribute(ServerRequestInterface::class)
            ? $this->renderingContext->getAttribute(ServerRequestInterface::class)
            : null;
        $grants = $request?->getAttribute(SubscriberGrants::ATTRIBUTE);
        if ($grants instanceof SubscriberGrants) {
            if ($this->arguments['authorize'] && $topics !== []) {
                $grants->authorize(...$topics);
                $cacheInstruction = $request->getAttribute('frontend.cache.instruction');
                if ($cacheInstruction instanceof CacheInstruction) {
                    $cacheInstruction->disableCache('EXT:mercure: subscriber cookie for private topics');
                }
            } else {
                $grants->markUsed();
            }
        }
        return SubscriptionUrl::build($this->hub, $topics, (string)$this->arguments['lastEventId']);
    }
}

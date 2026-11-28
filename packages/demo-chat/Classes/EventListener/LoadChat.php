<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\EventListener;

use TYPO3\CMS\Backend\Controller\Event\BeforeBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Loads the chat into the backend's main frame, once per backend login
 * (not per module, which runs in the content frame).
 */
final readonly class LoadChat
{
    #[AsEventListener('kate-demo-chat/load-chat')]
    public function __invoke(BeforeBackendPageRenderEvent $event): void
    {
        $event->pageRenderer->loadJavaScriptModule('@ochorocho/kate-demo-chat/demo-chat.js');
    }
}

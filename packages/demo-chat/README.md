# ochorocho/kate-demo-chat

A demo chat for the TYPO3 backend: docked to the right of every backend page, collapsed to a "Chat" tab or expanded to a panel, live for every backend user who is logged in. It shows what the site's [Mercure](https://mercure.rocks) hub can do, through [`ochorocho/typo3-mercure`](https://github.com/ochorocho/typo3-mercure).

```sh
./kate composer <site> require ochorocho/kate-demo-chat:@dev
./kate deploy <site>
```

Nothing else to configure: it uses the hub the Helm chart runs (`mercure.enabled`, on by default) and the variables it sets.

## How it works

```
browser (backend)                          TYPO3                                   Mercure hub
  <typo3-kate-demo-chat>  ──GET  subscribe──▶ ChatController::subscribeAction
                          ◀─ hub URL + subscriber cookie (topic urn:typo3-kate:demo-chat)
  EventSource ───────────────────────────────────────────────────────────────▶ /.well-known/mercure
  "Send"                  ──POST send──────▶ ChatController::sendAction ──publish (private)──▶
  EventSource ◀──────────────────────────────────────────────── the message, to every subscriber
```

- `LoadChat` (a `BeforeBackendPageRenderEvent` listener) loads `@ochorocho/kate-demo-chat/demo-chat.js` into the backend's main frame, once per login. The module defines `<typo3-kate-demo-chat>`, a [Lit](https://lit.dev) element using the backend's own Lit (import map), and adds it to the page.
- Both AJAX routes (`Configuration/Backend/AjaxRoutes.php`) need a backend login and the route's CSRF token. Who wrote a message comes from the login (real name, else user name), never from the browser.
- Messages are **private** Mercure updates: only browsers with the subscriber cookie, which `subscribe` hands out to logged-in backend users, receive them. The cookie lasts as long as EXT:mercure's `jwtLifetime` (an hour); the element subscribes again when it runs out.
- Messages are live only: nothing is stored, a backend opened later starts empty.
- Text: up to 1000 characters, control characters removed, rendered as text (never as HTML).
- The collapsed or expanded state is kept per browser (`localStorage`). Unread messages show as a badge on the collapsed tab.

Without a configured hub (`mercure.enabled: false`) the panel shows "The Mercure hub is not configured." and tries again every 10 seconds.

## Tests

```sh
../../projects/kate/vendor/bin/phpunit -c packages/demo-chat/phpunit.xml.dist
```

With the kate site's dependencies installed (`./kate composer kate install`).

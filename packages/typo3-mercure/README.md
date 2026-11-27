# ochorocho/typo3-mercure

[Mercure](https://mercure.rocks) for TYPO3, built on [symfony/mercure](https://github.com/symfony/mercure). It does for TYPO3 what MercureBundle does for Symfony:

- `Symfony\Component\Mercure\HubInterface`, `HubRegistry`, `Authorization` and `Discovery` as services
- publishing synchronously, or through TYPO3's message bus and the messenger worker
- `<mercure:url>` for Fluid, with subscriber cookies for private topics
- the `Link: <…>; rel="mercure"` discovery header
- `mercure:publish` on the CLI
- the hub's state in the backend's System Information menu

Mercure protocol 1.0 is the default (hub 1.x). Tokens are RFC 9068 access tokens (`typ: at+jwt`, `iss`, `aud`, `sub`, `client_id`, `authorization_details`), signed with HS256 by `lcobucci/jwt`.

## Configuration

Each setting of the extension configuration falls back to an environment variable when empty. The names are the ones the Helm chart in this repository sets.

| Setting | Environment | |
|---|---|---|
| `hubUrl` | `MERCURE_URL` | where TYPO3 publishes, e.g. `http://typo3-mercure/.well-known/mercure` |
| `publicUrl` | `MERCURE_PUBLIC_URL` | where browsers subscribe; also the token audience (`resource_identifier` of the hub). Empty: `hubUrl` |
| `publisherJwtKey` | `MERCURE_PUBLISHER_JWT_KEY` | HS256, at least 32 bytes |
| `subscriberJwtKey` | `MERCURE_SUBSCRIBER_JWT_KEY` | Empty: the publisher key |
| `jwtIssuer` | `MERCURE_JWT_ISSUER` | one of the hub's `MERCURE_TRUSTED_ISSUERS`. Empty: origin of `publicUrl` |
| `protocolVersion` | | `1.0` or `0.x` |
| `jwtLifetime` | | seconds, default 3600 |
| `cookieName` | | Empty: `__Secure-mercure_access_token` (1.0, needs HTTPS) |
| `transport` | | messenger transport for `Update` messages, default `doctrine`. Empty: synchronous |

Without `hubUrl` and `publisherJwtKey` the extension stays passive. Publishing then throws an exception that names the missing settings.

## Publishing

Right away, inside the request:

```php
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

public function __construct(private readonly HubInterface $hub) {}

$this->hub->publish(new Update('https://example.com/books/1', json_encode(['status' => 'sold'])));
```

Later, by the messenger worker (`messenger:consume doctrine`), so a slow or absent hub never delays a page:

```php
use Symfony\Component\Messenger\MessageBusInterface;

$this->messageBus->dispatch(new Update('https://example.com/books/1', $json));
```

`ext_localconf.php` routes `Update` to the `transport` setting, unless the project already routes it in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']`.

Private updates (`new Update($topic, $data, private: true)`) only reach subscribers whose token grants that topic.

## Subscribing

`<mercure:url>` is available in every template without importing a namespace:

```html
<div data-hub="{mercure:url(topics: {0: 'https://example.com/books/1'})}"></div>
<script type="module">
    const element = document.querySelector('[data-hub]');
    const source = new EventSource(element.dataset.hub, { withCredentials: true });
    source.onmessage = (event) => console.log(JSON.parse(event.data));
</script>
```

| Argument | |
|---|---|
| `topics` | topics to subscribe to (exact match) |
| `authorize` | also grant this visitor the private updates of these topics |
| `lastEventId` | resume after this event |

With `authorize="1"` the middleware sets the subscriber cookie on the response: an HttpOnly JWT, valid for the hub's path only, signed with the subscriber key. Its `sub` claim is `fe_users:<uid>`, `be_users:<uid>` or `anonymous`. Such a page holds a cookie for one visitor, so the ViewHelper turns off the page cache for it. Public topics leave the page cacheable.

The hub has to be on the site's own domain (or a subdomain of it) for the cookie to reach it. The chart serves it under `/.well-known/mercure` of the site.

## CLI

```sh
vendor/bin/typo3 mercure:publish https://example.com/books/1 '{"status":"sold"}'
vendor/bin/typo3 mercure:publish https://example.com/users/1 '{}' --private --type=notification
vendor/bin/typo3 mercure:publish https://example.com/books/1 '{}' --async   # via the message bus
```

## Worker mode

Every service is either readonly or rebuilt per request: tokens are minted per publish, and the subscriber grants live in the request (`SubscriberGrants`), never in a shared service.

## Tests

```sh
vendor/bin/phpunit -c packages/typo3-mercure/phpunit.xml.dist
```

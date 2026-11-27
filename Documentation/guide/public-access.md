# Public domain & Let's Encrypt

Every site can be served under its own public name, here `kate.ochorocho.dev`, with a trusted certificate from Let's Encrypt. The one site on the node IPs keeps answering by IP address with the [cluster CA](./typo3#https).

```
internet ──443──▶ FRITZ!Box ──443──▶ Mac (192.168.178.38) ──ssh tunnel──▶ Traefik on the master
                                                                       └─ certificate for kate.ochorocho.dev (Let's Encrypt)
```

## How the certificate is issued

The VMs aren't reachable from the internet on their own, so Let's Encrypt can't check the site over HTTP. cert-manager uses **DNS-01** instead: it puts a TXT record `_acme-challenge.kate.ochorocho.dev` into the zone through the Hetzner Cloud API, Let's Encrypt checks it, and the record is removed again. This works without any open port.

| Part | Created by | What it does |
|---|---|---|
| cert-manager v1.21.3 | `infra/` | requests and renews certificates (about 30 days before they expire) |
| `cert-manager-webhook-hetzner` | `infra/` | Hetzner's official DNS-01 solver (Hetzner Cloud API) |
| ClusterIssuers `letsencrypt-staging`, `letsencrypt` | `infra/`, while `acme:` is in `kate.yaml` | the two Let's Encrypt environments, shared by all sites |
| Certificate `site` → Secret `site-tls` | `./kate deploy`, in the site's namespace | the certificate for the site's domain |
| Gateway `site` | `./kate deploy`, in the site's namespace | one HTTPS listener (port 8443) for the domain, with `site-tls` |
| HTTPRoutes `typo3`, `media` | the chart / `./kate deploy` | attach to the site's Gateway, only for its hostname (the node-IP site's routes: any hostname, also on `public-gateway`) |

Traefik serves every Gateway on port 443 and picks the certificate by the name the browser asks for (SNI). infra's `public-gateway` in `gateway-system` keeps the http→https redirect and the `https` listener for the node IPs with the cluster CA.

## Set it up

**1. DNS (Hetzner Console):** the zone must be managed in the Hetzner Console, in the project the token belongs to. Add an A record for the name, pointing to your external IP (for access from the internet) or to `192.168.105.200` (only from your Mac).

**2. API token:** Hetzner Console → your project → Security → API tokens → **Read & Write**. Tokens from the old DNS Console don't work with the new API.

**3. Cluster settings** in `kate.yaml` in the repository root, your own file that git ignores ([your settings](./getting-started#your-settings)):

```yaml
acme:
  email: ""                      # optional
  hetznerDnsToken: "…"           # or leave empty and export TF_VAR_hetzner_dns_token
```

**4. Apply** the infrastructure. It creates the token Secret and the two ClusterIssuers and waits until they are ready. No VM is rebuilt: check that the plan replaces no `libvirt_domain`.

```sh
cd infra
tofu apply -var-file=macos.tfvars -parallelism=1
```

**5. Site settings** in `projects/kate/kate.yaml` (committed, no secrets):

```yaml
domain: kate.ochorocho.dev
acme:
  environment: staging           # then production
```

**6. Deploy** the site. It creates its Gateway and Certificate, waits until the certificate is `Ready` (DNS-01 takes a few minutes the first time), and sets the media and Mercure URLs to the domain:

```sh
cd .. && ./kate deploy kate --no-build
```

Start with `staging`: those certificates are untrusted on purpose, but failures don't count against [Let's Encrypt's rate limits](https://letsencrypt.org/docs/rate-limits/). Once it works, set `production` and deploy again.

## Another site

Each site with a domain repeats steps 1, 5 and 6: an A record for its domain (and the router forwarding below, if it isn't there yet), `domain` and `acme.environment` in its `projects/<name>/kate.yaml`, then `./kate deploy <name>`. The token in the root `kate.yaml` must be allowed to edit the domain's zone. `tofu` doesn't need to run again.

`./kate deploy` refuses a domain that another site already uses.

Only one site answers on the node IPs: the one with `nodeIPs: true`, or a site without a domain. Its routes have no hostname, so they match any name. Traefik ranks routes mostly by path length, not by hostname: a path that another site doesn't route itself can end up at the node-IP site.

## Migrating from the domain in `infra/`

Before sites had their own Gateways, `infra/` served the domain with an `https-domain` listener and a Certificate in `gateway-system`. On such a cluster, in this order:

1. `git pull`
2. `./kate deploy kate`: creates the Gateway `site` and its certificate in `typo3`. The old listener keeps serving the domain meanwhile.
3. `tofu apply` in `infra/`: removes the `https-domain` listener and the Certificate in `gateway-system`
4. optional: `kubectl -n gateway-system delete secret site-tls` (the old certificate)
5. remove `domain` and `acme.environment` from the root `kate.yaml` (`tofu` warns while they are there)

## Certificates across rebuilds

Let's Encrypt issues at most **5 certificates per week for the same domain**, with no exceptions, and the allowance refills only slowly (one every 34 hours). Certificates live in the cluster, so every rebuild, and every `./kate remove` followed by a new deploy, would order a new one.

That's why `./kate` keeps a copy of every issued certificate on the machine you deploy from: `~/.local/state/typo3-kate/certificates/<domain>.<issuer>.json`, readable only by you, because it holds the private key. `./kate deploy` writes it once the certificate is ready, and `./kate remove` writes it before deleting the namespace. When a site is deployed into a namespace without a certificate, `./kate deploy` restores the copy first if it is for the same domain and environment and valid for at least 7 more days. cert-manager then finds a valid certificate and orders nothing.

Only when there is no copy to restore does `./kate deploy` order a new production certificate, and it says so. Use `acme.environment: staging` while you rebuild clusters or try things out. `./kate new` suggests staging, and uses it when run without a terminal.

## The token on later runs

While the root `kate.yaml` has an `acme:` section, **every** `tofu apply` needs the token: from `kate.yaml`, or from `TF_VAR_hetzner_dns_token`. Without it, OpenTofu stops with an error instead of removing the issuers, which would stop every site's certificate from renewing. The token is also stored in the cluster, so you can keep it out of `kate.yaml` and read it from there:

```sh
export TF_VAR_hetzner_dns_token="$(ssh ubuntu@192.168.105.200 "sudo k3s kubectl -n cert-manager get secret hetzner -o jsonpath='{.data.token}' | base64 -d")"
```

## Check it

```sh
kubectl -n typo3 get certificate,gateway site            # READY True, PROGRAMMED True
openssl s_client -connect 192.168.105.200:443 -servername kate.ochorocho.dev </dev/null 2>/dev/null \
  | openssl x509 -noout -subject -issuer -enddate          # issuer: Let's Encrypt
```

## Reach it from the internet (Mac host)

The VMs live in a network inside the Mac (`192.168.105.x`). The router can only forward to the Mac, and the Mac passes the traffic on.

**Router (FRITZ!Box):** Internet → Freigaben → Portfreigaben → your Mac → TCP **443 → 443** (and **80 → 80** for the redirect). Give the Mac a fixed address there. Forward nothing else: 6443 (Kubernetes API), 30080 (dashboard), 30500 (registry) and 22 (ssh) must stay internal.

**Mac:** an SSH tunnel to Traefik on the master. `ssh` isn't affected by macOS's Local Network block:

```sh
ssh -N -o ExitOnForwardFailure=yes \
  -L 0.0.0.0:443:127.0.0.1:443 -L 0.0.0.0:80:127.0.0.1:80 ubuntu@192.168.105.200
```

It runs as long as the command runs, and the Mac must stay awake.

::: warning DDEV uses ports 80 and 443 too
`ddev-router` binds `127.0.0.1:80` and `:443`, and the tunnel then fails with `Address already in use`. Either stop DDEV (`ddev poweroff`) while the tunnel runs, or use other ports on the Mac and let the router translate:

```sh
ssh -N -o ExitOnForwardFailure=yes \
  -L 0.0.0.0:8443:127.0.0.1:443 -L 0.0.0.0:8081:127.0.0.1:80 ubuntu@192.168.105.200
```

FRITZ!Box: external 443 → Mac 8443, external 80 → Mac 8081.
:::

On the **Odroid** with `network_mode = "bridge"`, the VMs have their own LAN addresses: forward 443/80 straight to the master's IP, no tunnel needed.

## Switch it off

For one site: remove `domain` from its `projects/<name>/kate.yaml` and deploy it. It then answers on the node IPs, which only one site can. Delete its old Gateway and Certificate by hand: `kubectl -n <namespace> delete gateway,certificate site`.

For the whole cluster: remove the `acme:` section from the root `kate.yaml`, unset `TF_VAR_hetzner_dns_token` and apply in `infra/`. The issuers and the token Secret are removed, and no site's certificate renews any more. IP access with the cluster CA stays.

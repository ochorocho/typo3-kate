# Public domain & Let's Encrypt

The cluster can serve TYPO3 under a public name, here `kate.ochorocho.dev`, with a trusted certificate from Let's Encrypt. Access by IP address keeps working with the [cluster CA](./typo3#https).

```
internet ──443──▶ FRITZ!Box ──443──▶ Mac (192.168.178.38) ──ssh tunnel──▶ Traefik on the master
                                                                       └─ certificate for kate.ochorocho.dev (Let's Encrypt)
```

## How the certificate is issued

The VMs aren't reachable from the internet on their own, so Let's Encrypt can't check the site over HTTP. cert-manager uses **DNS-01** instead: it puts a TXT record `_acme-challenge.kate.ochorocho.dev` into the zone through the Hetzner Cloud API, Let's Encrypt checks it, and the record is removed again. This works without any open port.

| Part | What it does |
|---|---|
| cert-manager v1.21.3 | requests and renews certificates (about 30 days before they expire) |
| `cert-manager-webhook-hetzner` | Hetzner's official DNS-01 solver (Hetzner Cloud API) |
| ClusterIssuers `letsencrypt-staging`, `letsencrypt` | the two Let's Encrypt environments |
| Certificate `gateway-system/site` → Secret `site-tls` | the certificate for the domain |
| Gateway listener `https-domain` | serves the domain with `site-tls`; IP addresses keep the `https` listener with the cluster CA |

## Set it up

**1. DNS (Hetzner Console):** the zone must be managed in the Hetzner Console. Add an A record for the name, pointing to your external IP (for access from the internet) or to `192.168.105.200` (only from your Mac).

**2. API token:** Hetzner Console → your project → Security → API tokens → **Read & Write**. Tokens from the old DNS Console don't work with the new API.

**3. Settings** in `kate.yaml` in the repository root, your own file that git ignores ([your settings](./getting-started#your-settings)):

```yaml
domain: kate.ochorocho.dev
acme:
  environment: staging           # then production
  email: ""                      # optional
  hetznerDnsToken: "…"           # or leave empty and export TF_VAR_hetzner_dns_token
```

**4. Apply** the infrastructure, then deploy TYPO3, so its media and Mercure URLs use the domain:

```sh
cd infra
tofu apply -var-file=macos.tfvars -parallelism=1
cd .. && k8s/deploy.sh --no-build
```

The run copies the new manifests to the master and waits until the certificate is `Ready`. No VM is rebuilt: check that the plan replaces no `libvirt_domain`.

Start with `staging`: those certificates are untrusted on purpose, but failures don't count against [Let's Encrypt's rate limits](https://letsencrypt.org/docs/rate-limits/). Once it works, set `production` and apply again.

## The token on later runs

While a domain is set, **every** `tofu apply` needs the token: from `kate.yaml`, or from `TF_VAR_hetzner_dns_token`. Without it, OpenTofu stops with an error instead of removing the certificate setup. The token is also stored in the cluster, so you can keep it out of `kate.yaml` and read it from there:

```sh
export TF_VAR_hetzner_dns_token="$(ssh ubuntu@192.168.105.200 "sudo k3s kubectl -n cert-manager get secret hetzner -o jsonpath='{.data.token}' | base64 -d")"
```

## Check it

```sh
kubectl -n gateway-system get certificate site            # READY True
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

Remove `acme_domain` from the tfvars and apply. The domain listener, the issuers and the certificate are removed; IP access with the cluster CA stays.

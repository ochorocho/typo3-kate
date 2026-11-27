# Settings: kate.yaml

Two kinds of `kate.yaml` hold your settings:

| File | Holds | In git |
|---|---|---|
| `kate.yaml` in the repository root | the cluster: Hetzner DNS token, Let's Encrypt contact and wildcard, SSH keys, Helm values for every site | no (git-ignored), template `kate.example.yaml` |
| `projects/<name>/kate.yaml` | one site: domain, Let's Encrypt environment, namespace, node IPs, backend admin, its Helm values | only for `projects/kate` and `projects/_example`; your own sites stay local |

Every setting is optional. Without the root `kate.yaml` the cluster runs on IP addresses with its own CA; without a site's `kate.yaml` the site gets the defaults below (and, having no domain, answers on the node IPs).

## Root `kate.yaml`

```sh
cp kate.example.yaml kate.yaml && chmod 600 kate.yaml   # it holds the DNS token
```

| Setting | Default | Read by | |
|---|---|---|---|
| `acme.email` | none | OpenTofu | contact address of the Let's Encrypt account |
| `acme.hetznerDnsToken` | none | OpenTofu, `./kate dns` | shorthand for one provider `hetzner`: Hetzner Console API token (Read & Write). OpenTofu stores it in the cluster (Secret `hetzner` in `cert-manager`); `TF_VAR_hetzner_dns_token` works instead |
| `acme.providers.<name>` | none | OpenTofu, `./kate` | DNS providers for DNS-01, each under a name of your choice: Hetzner, Cloudflare, Route 53, DigitalOcean or RFC2136, see [DNS providers](#dns-providers) |
| `acme.defaultProvider` | `hetzner`, else the only provider | OpenTofu, `./kate` | the provider for sites without `acme.provider` (needed with several providers and none called `hetzner`) |
| `acme.wildcard.domain` | none | `./kate deploy`, `status`, `dns` | one certificate for `*.<domain>`, shared by every site exactly one level below it; see [one certificate for all subdomains](../guide/public-access#one-certificate-for-all-subdomains-wildcard) |
| `acme.wildcard.environment` | `staging` | `./kate deploy` | `staging` or `production` for the wildcard certificate |
| `acme.wildcard.provider` | `acme.defaultProvider` | `./kate deploy`, `dns` | the DNS provider for the wildcard certificate |
| `ssh.authorizedKeys` | `[]` | OpenTofu | more public keys for `ssh ubuntu@<node>`, added on every apply without rebuilding the VMs |
| `helm` | `{}` | `./kate deploy` | Helm values for every site, see [order of the Helm values](#order-of-the-helm-values) |

The `acme:` section turns on the Let's Encrypt ClusterIssuers: OpenTofu creates them as long as the section exists, and every `tofu apply` then needs the providers' credentials (without them the issuers would lose their Secrets and no certificate would renew). `domain` and `acme.environment` here are ignored (each site sets them itself); `tofu` warns while they are present.

### Full example

```yaml
acme:
  email: you@example.com
  defaultProvider: hetzner
  providers:
    hetzner:
      type: hetzner
      token: "your-hetzner-token"   # plain ASCII quotes
    cloudflare:
      type: cloudflare
      apiToken: "your-cloudflare-token"
  wildcard:
    domain: example.com             # *.example.com for shop.example.com, blog.example.com, …
    environment: production         # staging while you try things out
    provider: hetzner

ssh:
  authorizedKeys:
    - "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... you@laptop"

helm:                               # every site
  typo3:
    mailFrom: noreply@example.com
```

## `projects/<name>/kate.yaml`

`./kate new <name>` writes it from `projects/_example/kate.yaml`.

| Setting | Default | |
|---|---|---|
| `domain` | none | public hostname. The site gets its own Gateway and certificate, or uses the wildcard one if the domain is one level below `acme.wildcard.domain`. Without a domain the site answers on the node IPs |
| `acme.environment` | `staging` | `staging` or `production` for the site's own certificate. Ignored for a site under the wildcard |
| `acme.provider` | `acme.defaultProvider` | the DNS provider (a name from `acme.providers` in the root `kate.yaml`) that proves the site's domain. Ignored for a site under the wildcard, which uses `acme.wildcard.provider` |
| `namespace` | the folder name | Kubernetes namespace. `projects/kate` keeps `typo3` from before `projects/` existed |
| `nodeIPs` | `false` | also answer on `https://<node-ip>/` with the cluster CA. One site only; always `true` without a domain |
| `admin.username` | `admin` | backend user the first install creates (letters, digits, `. _ @ -`). Changing it later has no effect on an installed site |
| `admin.email` | `admin@typo3-kate.local` | that user's email address |
| `helm` | `{}` | Helm values of this site, applied last |

`./kate deploy` refuses a namespace another site owns, a domain another site uses and a second site on the node IPs.

### Full example

```yaml
domain: shop.example.com
acme:
  environment: production
  provider: cloudflare
namespace: shop
nodeIPs: false
admin:
  username: jochen
  email: jochen@example.com

helm:
  # TYPO3 scheduler, cron syntax in UTC
  scheduler:
    schedule: "*/5 * * * *"
  # Messenger worker: a list replaces the chart's command as a whole
  messenger:
    command:
      - vendor/bin/typo3
      - messenger:consume
      - doctrine
      - --time-limit=3600
      - --memory-limit=256M
  # a smaller site: one frontend pod, no autoscaling, no Mercure
  frontend:
    replicaCount: 1
    autoscaling:
      enabled: false
    pdb:
      enabled: false
  mercure:
    enabled: false
```

## DNS providers

The cluster isn't reachable from the internet, so Let's Encrypt can't check a site over HTTP; cert-manager proves the domain with a TXT record at its DNS provider instead (DNS-01). Each provider in `acme.providers` gets its credentials into a Secret in `cert-manager` and a solver in both ClusterIssuers (`letsencrypt`, `letsencrypt-staging`) for Certificates labelled `typo3-kate/dns-provider: <name>`; `./kate deploy` sets that label from the site's `acme.provider`. The default provider also takes Certificates without a label, such as those from before `acme.providers`.

The domain must be in a zone that the credentials may edit. After adding or changing a provider, run `tofu apply` in `infra/`, then `./kate deploy` the sites that use it.

| `type` | Settings | Credentials |
|---|---|---|
| `hetzner` | `token` | Hetzner Console → project → Security → API tokens, Read & Write. Uses the official Hetzner webhook for cert-manager |
| `cloudflare` | `apiToken` | Cloudflare → My Profile → API Tokens → "Edit zone DNS" template (Zone:DNS:Edit, Zone:Read) for the zones |
| `route53` | `region`, `accessKeyId`, `secretAccessKey`, optional `hostedZoneId` | an IAM user with `route53:GetChange`, `route53:ChangeResourceRecordSets`, `route53:ListResourceRecordSets` and `route53:ListHostedZonesByName` |
| `digitalocean` | `token` | DigitalOcean → API → Tokens, with write access to domains |
| `rfc2136` | `nameserver` (`host:port`), `tsigKeyName`, `tsigSecret` (base64), optional `tsigAlgorithm` (default `HMACSHA256`) | a TSIG key the DNS server accepts for dynamic updates of the zone (BIND, PowerDNS, Knot, many hosters) |

```yaml
acme:
  providers:
    hetzner:
      type: hetzner
      token: "…"
    cloudflare:
      type: cloudflare
      apiToken: "…"
    aws:
      type: route53
      region: eu-central-1
      accessKeyId: "AKIA…"
      secretAccessKey: "…"
      hostedZoneId: "Z0123456789"     # optional, else found by the domain
    digitalocean:
      type: digitalocean
      token: "…"
    bind:
      type: rfc2136
      nameserver: "203.0.113.53:53"
      tsigKeyName: kate.
      tsigAlgorithm: HMACSHA256
      tsigSecret: "…"
```

`tofu apply` refuses a provider without a known `type`, one with missing settings (and names them), and several providers without `defaultProvider` when none is called `hetzner`. `./kate deploy` refuses a site whose `acme.provider` isn't defined.

`./kate dns` creates the wildcard DNS record only at Hetzner; with another provider for the wildcard, create the A record `*.<domain>` there yourself.

Other providers (deSEC, INWX, OVH, Gandi, …) exist as cert-manager webhooks like Hetzner's; adding one means a solver type in `infra/dns.tf` and its webhook chart in `infra/templates/cert-manager.yaml.tftpl`.

## Order of the Helm values

`./kate deploy` passes these to `helm upgrade --install`, the last one wins:

1. `k8s/values/cluster.yaml`: the defaults for this cluster
2. `helm:` in the root `kate.yaml`: every site
3. what `./kate values` derives from the site's domain: media and Mercure URLs, where the HTTPRoute attaches, its hostnames
4. `helm:` in `projects/<name>/kate.yaml`: this site

Lists are replaced, not merged: to change one option of the messenger command, give the whole command. Any value of `k8s/charts/typo3/values.yaml` can be set.

## Not in kate.yaml

- the host and VM sizes: `infra/*.tfvars` (see [Variables](./variables))
- passwords and keys of a site: generated by `./kate deploy` into the site's Secrets (`./kate password <name>` shows the backend login)
- saved certificate copies: `~/.local/state/typo3-kate/certificates/` (`./kate certificates`)

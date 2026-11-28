# TYPO3 on the cluster

The cluster runs TYPO3 v15 sites, built and deployed the way [Benni Mack's typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter) does it. The same production image and Helm chart run here, on a real 3-node cluster, with the database and caches inside the cluster. Each site has its own namespace, image, database and domain; the first one is `kate`.

The commands on this page use `-n typo3`, the namespace of `kate`. For another site use its namespace (by default its folder name).

| What | Where |
|---|---|
| Website | `https://192.168.105.200/` (every node IP works; `http://` redirects) |
| Backend | `https://192.168.105.200/typo3`, user `admin` |
| Health | `/_health/live`, `/_health/ready` |

## HTTPS

TLS ends at the Gateway, as in Benni's production setup. `tofu apply` creates a private CA for the cluster and a certificate for every node IP and hostname, valid for 397 days. Plain HTTP answers with a redirect to HTTPS. Sites with a domain get a Let's Encrypt certificate instead: [Public domain & Let's Encrypt](./public-access).

Trust the CA once, and the browser stops warning:

```sh
# macOS: trust the cluster CA once (asks for your password)
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain infra/cluster-ca.crt
```

Firefox has its own certificate store: Settings → Privacy & Security → Certificates → View Certificates → Authorities → Import.

The admin password is in a Secret:

```sh
./kate password kate          # user and password; --copy puts the password on the clipboard
```

## Sites in `projects/`

Every folder in `projects/` is one site:

```
projects/<name>/
├── kate.yaml        settings of the site (no secrets)
├── composer.json    the site's packages; path repository ../../packages/*
├── composer.lock
├── config/sites/    site configuration
└── packages/        the site's own extensions, e.g. its site package (path repository packages/*)
```

Only `projects/_example` (the template) and `projects/kate` are part of this repository. Every other folder in `projects/` is git-ignored, so your own sites stay local, for example as a git repository of their own cloned into `projects/<name>/`. `./kate deploy` doesn't care either way.

Shared by all sites, in the repository root: `packages/` (`k8s-health`, `k8s-starter`), `config/system/` (the cluster glue: all configuration from environment variables; a site must not have its own, the image build refuses it), `docker/`, `k8s/` and `infra/`.

`projects/<name>/kate.yaml`:

| Setting | Default | |
|---|---|---|
| `domain` | none | public hostname. The site gets its own Gateway and Let's Encrypt certificate for it, see [Public domain & Let's Encrypt](./public-access). Without a domain the site answers on the node IPs |
| `acme.environment` | `staging` | `staging` (untrusted test certificates) or `production` |
| `namespace` | the folder name | Kubernetes namespace of the site |
| `nodeIPs` | `false` | also answer on `https://<node-ip>/` with the cluster CA. Only one site can |
| `imgproxy.enabled` | `false` | process images with [imgproxy](#images-imagemagick-or-imgproxy) instead of ImageMagick |
| `php` | `8.5` | PHP version, `8.5` or `8.4` (those have a [prebuilt base image](#the-image)); the site's composer packages must allow it |
| `admin.username` | `admin` | backend user that the first install creates; changing it later has no effect on an installed site |
| `admin.email` | `admin@typo3-kate.local` | that user's email address |
| `helm` | `{}` | Helm values of this site, applied last |

Full examples of both files: [Settings (kate.yaml)](../reference/settings).

`projects/kate` is the site from before `projects/` existed: namespace `typo3` (so its database and files stay), `nodeIPs: true`, domain `kate.ochorocho.dev`.

### A new site

```sh
./kate new shop --domain shop.example.com --acme staging   # or just ./kate new: it asks
./kate deploy shop
```

`./kate new` creates `packages/` for the site's own extensions, adds the path repository `packages/*` to its `composer.json` (an extension in `packages/<name>/` is then installed with `./kate composer <site> require <vendor/name>:@dev`), copies `composer.json`, `composer.lock` and `config/sites/` from an existing site (`--from <site>`, asked for when there are several) and writes `projects/shop/kate.yaml` from the template with the domain, the Let's Encrypt environment and, if it differs from the name, the namespace. `--node-ips` lets the site answer on the node IPs instead; without a domain it always does. `--garage-ui` switches on [Garage's web admin](#garage-ui) (`garage.ui: true`); it is off by default, and asked for when run in a terminal (`--no-garage-ui` doesn't ask). Edit `composer.json` and the `helm:` section afterwards as needed (`./kate composer <site> update` rewrites the lock file).

The domain needs an A record pointing at your public IP and must be in a Hetzner DNS zone the token may edit (see [Public domain & Let's Encrypt](./public-access#another-site)). Folders starting with `_` (like `_example`) are templates and are skipped by `--all`. Check the memory first: [Odroid H2 host](./odroid#several-sites).

### Composer

`./kate composer <site>` runs Composer for a site in its base image, with the site's PHP version and the same extensions as its production image (`ext-redis` included), so the PHP on your machine doesn't matter and `--ignore-platform-reqs` isn't needed. It works in `projects/<site>/` with the repository mounted (the path repositories resolve), writes files as you, uses your Composer cache and passes your `auth.json` on:

```sh
./kate composer kate install
./kate composer kate update typo3/cms-core
./kate composer shop require vendor/package
```

Plain `composer` in the site's folder works too, if your PHP fits the site: TYPO3's Composer plugin runs core code after every install, and TYPO3 v15 needs PHP 8.5.

## Deploy

Requirements: a cluster from `infra/` (`tofu apply`), plus [`gum`](https://github.com/charmbracelet/gum), `docker`, `kubectl`, `helm`, `jq`, `yq`, `openssl` and `tofu` on your machine.

```sh
./kate                      # menu: deploy, new, status, remove
./kate deploy               # asks which site (kate itself only by name), whether to build, then a summary to confirm
./kate deploy kate          # one site, no questions
./kate deploy --all         # every site in projects/ except kate, one after the other
./kate status               # every site: namespace, domain, release, certificate, pods
./kate password kate        # backend user and password (--copy: to the clipboard)
./kate logs kate frontend -f   # logs of a site's pods, prefixed with pod and container
./kate autocomplete         # tab completion for your shell, once
```

`./kate autocomplete` detects zsh or bash from `$SHELL` (or takes `zsh`/`bash`), writes the completion to `~/.config/typo3-kate/completion.<shell>` and adds one line that loads it to `~/.zshrc` or `~/.bashrc`; running it again only updates the file. It completes the commands, their options and the site names from the `projects/` folder next to the `kate` you typed, so new sites need no reinstall. `--print` writes the completion to stdout instead.

`./kate` asks only for what is missing on the command line. With every argument given it asks nothing, so it works in scripts and CI; without a terminal a missing argument is an error that names it.

The script:

1. refuses to start when the namespace belongs to another site, when the domain is already another site's, or when a second site would answer on the node IPs (a site without a domain always does)
2. builds `docker/Dockerfile` with `--build-arg PROJECT=<name>` and the site's `php` version on the [prebuilt base image](#the-image), for the nodes' architecture, and pushes it to the cluster registry as `<master-ip>:30500/<name>`. The release then uses the image's **digest**, not a tag.
3. creates the site's namespace and, the first time only, the Secrets with random passwords
4. with a domain: applies the site's Gateway `site`, Certificate `site` and the `/media/` route (`./kate manifests`) and waits for the certificate
5. applies `k8s/platform/` (MariaDB, two Valkey instances, Garage, two RWX volumes) and waits until they are ready
6. **empty database only:** runs `typo3 setup` in a one-off pod (schema and admin user), then imports the root page, About and Contact (`k8s/bootstrap.sql`)
7. runs `helm upgrade --install` (release `typo3` in every namespace) with `k8s/charts/typo3`. Before the new pods start, the chart's migrations Job runs `extension:setup`, `upgrade:run` and `cache:flush --group pages`.

Helm values, the last one wins:

1. `k8s/values/cluster.yaml`
2. `helm:` in the root `kate.yaml` (every site)
3. what `./kate values` derives from the domain: media and Mercure URLs, where the HTTPRoute attaches, its hostnames
4. `helm:` in `projects/<name>/kate.yaml`

Running it again is safe: Secrets, the database and the pages are left as they are, and the new image rolls out without downtime.

```sh
./kate deploy kate --no-build    # re-apply values/chart with the deployed image
```

### The image

Every site's image starts from a prebuilt runtime base: FrankenPHP with the PHP extensions TYPO3 needs (intl, gd, mysqli, pdo_mysql, zip, exif, opcache, redis) and the production `php.ini`, on Debian 13 (`docker/base.Dockerfile`). It is published for PHP 8.5 and 8.4, for amd64 and arm64, in two variants:

```
ghcr.io/ochorocho/typo3-kate-base:8.5            ImageMagick 7 and Ghostscript
ghcr.io/ochorocho/typo3-kate-base:8.5-imgproxy   no image tools, for sites with imgproxy
ghcr.io/ochorocho/typo3-kate-base:8.4            (and :8.4-imgproxy)
ghcr.io/ochorocho/typo3-kate-base:8.5-<yyyymmdd> (each build, to go back to)
```

So a deploy only adds the site's composer packages and code; nothing is compiled on the Odroid. The workflow `.github/workflows/base-image.yml` builds it on every change to the base and weekly without cache, for security updates; CI builds and checks every version and variant first. A site picks its version with `php:` in its `kate.yaml` (default 8.5), and its composer packages must allow it: TYPO3 v15 (`dev-main`, the kate site) needs 8.5, v14 also runs on 8.4. The variant follows `imgproxy.enabled`.

A base built locally instead (offline, or to try a change): `docker build -f docker/base.Dockerfile --build-arg PHP_VERSION=8.5 -t <registry>/typo3-kate-base:8.5 .` (add `--build-arg IMAGE_TOOLS=none` and tag `:8.5-imgproxy` for the other variant), pushed where your build can pull it, then `BASE_IMAGE=<registry>/typo3-kate-base ./kate deploy <site>`.

### Images: ImageMagick or imgproxy

By default TYPO3 processes images itself with ImageMagick 7. With `imgproxy.enabled: true` in a site's `kate.yaml`, [imgproxy](https://github.com/imgproxy/imgproxy) does it instead, through [EXT:media_processing](https://github.com/somehow-digital/typo3-media-processing):

```sh
./kate composer shop require ochorocho/kate-imgproxy:@dev   # once: EXT:media_processing + the glue
# projects/shop/kate.yaml:  imgproxy: { enabled: true }   (the site needs a domain)
./kate deploy shop
```

What changes for that site:

- its image builds on the `-imgproxy` base, without ImageMagick; TYPO3's own image processor is switched off
- an imgproxy Deployment and Service run in its namespace, internal only; NetworkPolicies let only the TYPO3 pods use it, and imgproxy only reach Traefik
- imgproxy fetches the originals through the site's own domain, so `fileadmin` and the S3 storage both work. A CoreDNS rule (`kube-system/coredns-custom`, key `typo3-kate-<namespace>.override`) resolves the domain to Traefik inside the cluster, instead of out to the router and back; imgproxy accepts only URLs below the site's URL. With a Let's Encrypt staging certificate it skips the certificate check
- TYPO3 stores each result in `_processed_` and serves it from there. EXT:media_processing would hand browsers the internal imgproxy URL; `packages/imgproxy` (`ochorocho/kate-imgproxy`) configures the extension from the environment and serves the stored file instead
- URLs are signed: `IMGPROXY_KEY` and `IMGPROXY_SALT` in the site's `typo3-env`, created by `./kate deploy`

`./kate deploy` refuses imgproxy for a site without a domain or without `ochorocho/kate-imgproxy` in its `composer.lock`. Switching it off again removes the CoreDNS rule; TYPO3 then processes images itself again. Keep the package installed when you switch off: without `TYPO3_IMGPROXY_URL` it does nothing, and the switch back needs its command (below).

**Switching makes the images again.** TYPO3 only processes an image when its processed file is missing, so the ones made the old way would stay in use. `./kate deploy` records the way in the namespace annotation `typo3-kate/images` (`imagemagick` or `imgproxy`); when it changes, it runs `./kate reprocess` after the new pods are up. That deletes the processed files of every storage, `fileadmin` and S3, through each storage's own driver (`vendor/bin/typo3 kate:processed-files:reset` from `ochorocho/kate-imgproxy`), and flushes the page cache, so cached pages don't point to deleted files. Each image is then made again on its first request, so the first visits are slower. Run it by hand any time:

```sh
./kate reprocess shop
```

Without the package it falls back to `cleanup:localprocessedfiles --all` (EXT:lowlevel), which covers only storages with the Local driver, not S3.

::: warning TYPO3 v15
EXT:media_processing 1.0 allows Symfony 7 only, TYPO3 v15 (`dev-main`) is on Symfony 8 ([upstream issue #41](https://github.com/somehow-digital/typo3-media-processing/issues/41)). On TYPO3 v14 it installs as it is. On v15, require it with an inline alias that lets Symfony 8 count as 7.x for this one requirement:

```sh
./kate composer shop require "symfony/options-resolver:8.1.10 as 7.4.99" ochorocho/kate-imgproxy:@dev
```

The extension only uses `setDefaults()` and `resolve()` of the OptionsResolver, both unchanged in Symfony 8. Use the version your lock file has, adjust it on Symfony updates, and drop the line once #41 is released.
:::

::: tip macOS: kubectl blocked
If macOS blocks `kubectl` from reaching the VMs (see [Troubleshooting](./troubleshooting#no-route-to-host-from-kubectl-or-the-browser-while-ssh-and-ping-work)), the script switches to an SSH tunnel to the master by itself, on the first free local port from 16443 to 16463, so several deploys can run at the same time.
:::

## What runs

```sh
kubectl -n typo3 get pods -o wide
```

| Workload | Replicas | Role |
|---|---|---|
| `typo3-frontend` | 2 (HPA 2–4) | website, one pod per worker |
| `typo3-backend` | 1 | `/typo3` for editors, routed separately |
| `typo3-scheduler` | CronJob every minute | TYPO3 scheduler |
| `typo3-messenger` | 1 | `messenger:consume doctrine`, async messages |
| `typo3-mercure` | 1, optional (`mercure.enabled`) | Mercure hub, real-time push under `/.well-known/mercure` |
| `typo3-mariadb-0` | 1 | MariaDB 11.8 via mariadb-operator |
| `typo3-valkey-0` | 1 | sessions and locks (persistent) |
| `typo3-valkey-cache` | 1 | TYPO3 caches (in memory only) |
| `garage-0` | 1 | S3 for storage 2 "Media (S3)" (Garage) |

Files in `fileadmin/` and `typo3temp/assets/` are on NFS volumes that all pods share.

### Service status in the backend

Admins see the state of the services in the backend top bar, in **System Information** (the info icon). The badge turns yellow or red when something is wrong.

| Row | From | Red when |
|---|---|---|
| Pod | `bmack/k8s-health` | – (the pod that served the backend) |
| Valkey (sessions, locks), Valkey (caches) | `bmack/k8s-health` | no PONG within 1 s |
| Messenger worker | `bmack/k8s-health`, heartbeats of `messenger:consume` every 30 s | no heartbeat for 90 s, or stopped for more than a minute |
| Message queue | `bmack/k8s-health`, `sys_messenger_messages` | oldest waiting message older than 30 min (yellow: 5 min) |
| Mercure hub | `ochorocho/typo3-mercure` | the hub doesn't answer at `MERCURE_URL` |

Only database and Valkey count for `/_health/ready`: pages work without the worker and the hub.

### Mercure

[`ochorocho/typo3-mercure`](https://packagist.org/packages/ochorocho/typo3-mercure) connects TYPO3 to the hub. It reads the variables the chart sets (`MERCURE_URL`, `MERCURE_PUBLIC_URL`, the JWT keys, `MERCURE_JWT_ISSUER`). The hub is on by default (`mercure.enabled: true` in `k8s/values/cluster.yaml`); a site can switch it off with `mercure.enabled: false` in its `helm:`. Try it:

```sh
kubectl -n typo3 exec deploy/typo3-backend -- vendor/bin/typo3 mercure:publish https://kate.ochorocho.dev/demo '{"hello":"world"}'
```

Usage from PHP and Fluid: [the extension's README](https://github.com/ochorocho/typo3-mercure#readme).

### Demo chat

`packages/demo-chat` (`ochorocho/kate-demo-chat`) is a chat for backend users that runs through the site's hub: a "Chat" tab on the right of the backend, expanded to a panel and collapsed again, live for everyone logged in to the backend. Any site can require it:

```sh
./kate composer shop require ochorocho/kate-demo-chat:@dev
./kate deploy shop
```

Messages are private Mercure updates: only logged-in backend users receive them, the sender's name comes from their login, and nothing is stored. Details: [its README](https://github.com/ochorocho/typo3-kate/tree/main/packages/demo-chat#readme).

## S3 storage ("Media (S3)")

Storage 2 in `config/system/file-storages.yaml` uses the S3 driver (b13/typo3-s3-driver) with **Garage** in the cluster, as Benni's starter does in DDEV. TYPO3 writes over the S3 API (`http://garage:3900`, bucket `media`, prefix `typo3`). Browsers load the files anonymously from `https://kate.ochorocho.dev/media/typo3/…`: the site's HTTPRoute `media` sends `/media/` to Garage's web endpoint. Every site has its own Garage.

The access key is generated once by `./kate deploy` (Secret `typo3-garage`) and also written to `typo3-env` as `TYPO3_S3_KEY` / `TYPO3_S3_SECRET`. Garage sets up its layout, the bucket and the key by itself (`--single-node --default-bucket`).

```sh
kubectl -n typo3 exec garage-0 -- /garage bucket info media    # size, objects, keys
```

### Garage UI

Garage (2.6 and later) has a web admin under `/ui/` on its admin port 3903: cluster health, nodes and layout, buckets, access keys and their permissions, maintenance tasks. Every site has its own Garage, and so its own UI and admin token.

It is **off by default**: Garage then doesn't open the admin port at all. Switch it on per site, in `projects/<site>/kate.yaml`, and deploy (Garage restarts once, so storage 2 is briefly unavailable):

```yaml
garage:
  ui: true
```

Even then the port is never exposed, because the admin token can change every bucket and key. `./kate garage` opens it for as long as the command runs:

```sh
./kate garage ochorocho                                # only on this machine: http://127.0.0.1:39361/ui/
./kate garage ochorocho --address 192.168.178.114      # from the home network, e.g. on the Odroid
./kate garage ochorocho --copy                         # the token to the clipboard instead of the terminal
```

It starts a `kubectl port-forward` to `garage-0` and prints the URL and the admin token (Secret `typo3-garage`, decoded). Log in with that token; Ctrl-C closes the port-forward again.

| Option | Default | |
|---|---|---|
| `--address <ip>` | `127.0.0.1` | the address to listen on; another machine's browser needs the host's LAN address |
| `--port <n>` | the site's port | the local port |
| `--copy` | | the token to the clipboard (pbcopy, wl-copy, xclip or xsel) |

**One port per site.** Each site gets its own port, the same every time (39000 plus a checksum of the namespace). `./kate status` lists it in the column "Garage UI", or `off`. The browser keeps the UI's login per address, so separate ports keep each site's login apart.

**`Forbidden: Invalid bearer token`** means the UI sent a token that isn't this Garage's:

- another site's login under the same address and port (e.g. after `--port` with the same number for two sites): log out in the UI, or let `./kate garage` choose the port
- the token copied still base64-encoded, from `kubectl get secret typo3-garage -o yaml` or the dashboard: `./kate garage` prints it decoded

**Careful:**

- With `--address` set to a LAN address, every device on that network can reach the login page while the command runs. Never forward the port on the router.
- Don't delete the `default access key` or change its permissions: TYPO3 uses it, and storage 2 goes offline without it.

Without the UI, the CLI in the pod needs no token: `kubectl -n <namespace> exec garage-0 -- /garage bucket list` (or `key list`, `status`).

::: tip `ssh -L` fails with "administratively prohibited"
If the host's sshd has `AllowTcpForwarding no`, SSH tunnels to it are refused. Run `./kate garage` on the host itself with `--address <its LAN address>` instead.
:::

Garage runs as a single node on one local-path volume: no replication, and the data is gone with the VM disk. Back up the bucket, or move to a managed S3, before relying on it.

## Update the site

Change code, templates or `projects/<name>/composer.lock`, then deploy:

```sh
./kate deploy kate
kubectl -n typo3 rollout status deploy/typo3-frontend
```

A rolling update keeps at least one frontend pod serving (`maxUnavailable: 0`, PodDisruptionBudget). Schema changes must work with old and new code side by side, because both run during the rollout.

## Roll back

```sh
helm -n typo3 history typo3
helm -n typo3 rollback typo3 <revision>
```

Rolling back changes the image and the settings. It doesn't undo database changes that a migration already made.

## Logs and CLI

Logs are JSON lines on stderr (b13/stderr-logging):

```sh
./kate logs kate frontend -f            # follow; also --since 1h, --tail 200
./kate logs kate migrations             # every step of the last migrations Job
./kate logs kate                        # all TYPO3 pods; components: typo3 frontend backend messenger
                                        # scheduler mercure migrations mariadb valkey valkey-cache garage
```

TYPO3 CLI:

```sh
kubectl -n typo3 exec deploy/typo3-backend -- vendor/bin/typo3 list
kubectl -n typo3 exec deploy/typo3-backend -- vendor/bin/typo3 cache:flush
```

## Scale

```sh
kubectl -n typo3 get hpa
```

The frontend scales between 2 and 4 pods with CPU load (`frontend.autoscaling` in `k8s/values/cluster.yaml`). For more than 4, also raise the worker memory in `infra/`.

## Start over

This deletes the site, the database and all uploaded files:

```sh
./kate remove kate
```

See [Remove a site](./operations#remove-a-site). The NFS volumes use `reclaimPolicy: Retain`, so their files stay in `/srv/nfs` on the master until you delete them there. The next `./kate deploy kate` installs a fresh site.

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
└── packages/        optional: packages of this site only
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
| `helm` | `{}` | Helm values of this site, applied last |

`projects/kate` is the site from before `projects/` existed: namespace `typo3` (so its database and files stay), `nodeIPs: true`, domain `kate.ochorocho.dev`.

### A new site

```sh
./kate new shop --domain shop.example.com --acme staging   # or just ./kate new: it asks
./kate deploy shop
```

`./kate new` copies `composer.json`, `composer.lock` and `config/sites/` from an existing site (`--from <site>`, asked for when there are several) and writes `projects/shop/kate.yaml` from the template with the domain, the Let's Encrypt environment and, if it differs from the name, the namespace. `--node-ips` lets the site answer on the node IPs instead; without a domain it always does. Edit `composer.json` and the `helm:` section afterwards as needed (`composer update` in the folder rewrites the lock file).

The domain needs an A record pointing at your public IP and must be in a Hetzner DNS zone the token may edit (see [Public domain & Let's Encrypt](./public-access#another-site)). Folders starting with `_` (like `_example`) are templates and are skipped by `--all`. Check the memory first: [Odroid H2 host](./odroid#several-sites).

### Composer

Composer runs inside the site's folder, locally as in the image build:

```sh
cd projects/kate
composer install
composer update typo3/cms-core
```

## Deploy

Requirements: a cluster from `infra/` (`tofu apply`), plus [`gum`](https://github.com/charmbracelet/gum), `docker`, `kubectl`, `helm`, `jq`, `yq`, `openssl` and `tofu` on your machine.

```sh
./kate                      # menu: deploy, new, status, remove
./kate deploy               # asks which site (kate itself only by name), whether to build, then a summary to confirm
./kate deploy kate          # one site, no questions
./kate deploy --all         # every site in projects/ except kate, one after the other
./kate status               # every site: namespace, domain, release, certificate, pods
./kate password kate        # backend user and password (--copy: to the clipboard)
./kate autocomplete         # tab completion for your shell, once
```

`./kate autocomplete` detects zsh or bash from `$SHELL` (or takes `zsh`/`bash`), writes the completion to `~/.config/typo3-kate/completion.<shell>` and adds one line that loads it to `~/.zshrc` or `~/.bashrc`; running it again only updates the file. It completes the commands, their options and the site names from the `projects/` folder next to the `kate` you typed, so new sites need no reinstall. `--print` writes the completion to stdout instead.

`./kate` asks only for what is missing on the command line. With every argument given it asks nothing, so it works in scripts and CI; without a terminal a missing argument is an error that names it.

The script:

1. refuses to start when the namespace belongs to another site, when the domain is already another site's, or when a second site would answer on the node IPs (a site without a domain always does)
2. builds `docker/Dockerfile` with `--build-arg PROJECT=<name>` for the nodes' architecture and pushes it to the cluster registry as `<master-ip>:30500/<name>`. The release then uses the image's **digest**, not a tag.
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

## S3 storage ("Media (S3)")

Storage 2 in `config/system/file-storages.yaml` uses the S3 driver (b13/typo3-s3-driver) with **Garage** in the cluster, as Benni's starter does in DDEV. TYPO3 writes over the S3 API (`http://garage:3900`, bucket `media`, prefix `typo3`). Browsers load the files anonymously from `https://kate.ochorocho.dev/media/typo3/…`: the site's HTTPRoute `media` sends `/media/` to Garage's web endpoint. Every site has its own Garage.

The access key is generated once by `./kate deploy` (Secret `typo3-garage`) and also written to `typo3-env` as `TYPO3_S3_KEY` / `TYPO3_S3_SECRET`. Garage sets up its layout, the bucket and the key by itself (`--single-node --default-bucket`).

```sh
kubectl -n typo3 exec garage-0 -- /garage bucket info media    # size, objects, keys
```

### Garage web GUI

Garage has a web admin GUI on its admin port 3903 under `/ui/`. The port is deliberately not exposed: the admin token can change every bucket and key. Open it temporarily with `kubectl port-forward`, listening on the host's LAN address (here the Odroid):

```sh
ssh -t jochen@192.168.178.114 \
  '~/.local/bin/kubectl --kubeconfig ~/typo3-kate/infra/kubeconfig -n typo3 port-forward --address 192.168.178.114 pod/garage-0 3903:3903'
```

Then open http://192.168.178.114:3903/ui/ and log in with the admin token:

```sh
kubectl -n typo3 get secret typo3-garage -o jsonpath='{.data.admin-token}' | base64 -d; echo
```

While the port-forward runs, every device on the home network can reach the login page; Ctrl+C closes it. Never forward port 3903 on the router. Don't delete the `default access key` or change its permissions: TYPO3 uses it, and storage 2 goes offline without it.

::: tip `ssh -L` fails with "administratively prohibited"
The server's sshd has `AllowTcpForwarding no`, so SSH tunnels to it are refused. That's why the port-forward above listens on the LAN address instead.
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
kubectl -n typo3 logs deploy/typo3-frontend -f
kubectl -n typo3 logs -l app.kubernetes.io/component=migrations --all-containers
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

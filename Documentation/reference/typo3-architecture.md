# TYPO3 architecture

## Where it comes from

The TYPO3 layer is taken from [bmack/typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter) by Benni Mack (MIT, upstream commit `2b88541`, 2026-11-26). Benni's repo develops in DDEV and rehearses in a k3s container next to DDEV. Here the same image and chart run on the libvirt cluster, with the database and caches inside the cluster.

| Taken unchanged | Purpose |
|---|---|
| `k8s/charts/typo3/` | Helm chart: frontend and backend Deployments, migrations Job (Helm hook), scheduler CronJob, HPA, PDB, HTTPRoute, NetworkPolicy, hardened pods |
| `config/system/additional.php`, `kubernetes.php`, `file-storages.yaml` | all configuration from `TYPO3_*` environment variables |
| `packages/k8s-health` | `/_health/live` and `/_health/ready` (database + Valkey) |
| `packages/k8s-starter` | site package (Site Set `bmack/k8s-starter`) |
| `composer.json`, `composer.lock` | TYPO3 v15 (`dev-main`), b13 S3 driver, stderr logging, distributed locks |
| `docker/Dockerfile` (one step added), `docker/php/` | FrankenPHP 1 / PHP 8.5 image: UID 1000, port 8080, read-only filesystem |

| Adapted | Change |
|---|---|
| `k8s/platform/mariadb.yaml` | from `examples/mariadb`: 1 replica instead of 3, 1 GiB, local-path storage, no metrics |
| `k8s/platform/valkey.yaml` | from `examples/valkey`: cache limited to 256 MB |
| `k8s/platform/storage.yaml` | from `examples/storage`: StorageClass `nfs` |
| `k8s/platform/garage.yaml` | new: Garage v2.6 single node for storage 2, a NetworkPolicy that lets TYPO3 reach it (the chart's own policy doesn't), and the `/media/` route |
| `infra/templates/gateway.yaml.tftpl` | from `examples/gateway`: Traefik's Gateway API provider, certificate from the cluster CA instead of cert-manager |
| `k8s/values/cluster.yaml` | from `values/production.yaml`: our hosts, Secret, services, volumes, Mercure on |
| `k8s/charts/typo3/` | messenger worker on by default; optional Mercure hub (`mercure.enabled`) with its route under `/.well-known/mercure` and NetworkPolicy |

| New | Why |
|---|---|
| `config/sites/main/config.yaml` | In Benni's repo, `ddev setup` creates it. Here it is versioned, so the image contains it. |
| `k8s/deploy.sh` | the counterpart of his `ddev k8s-deploy`, for a real cluster |
| `k8s/bootstrap.sql` | root page and sample pages (his `seed.sql` expects the root page from `typo3 setup`) |
| `docker/prepare-settings.php` | brings `settings.php` up to date at build time, see below |
| `packages/k8s-health` (extended) | besides the probes: Valkey, messenger worker (heartbeats in `tx_k8shealth_messenger_worker`) and queue in the backend's System Information menu |
| `packages/typo3-mercure` | `ochorocho/typo3-mercure`: symfony/mercure wired into TYPO3 (services, message bus, Fluid, subscriber cookies, CLI), configured by the chart's `MERCURE_*` variables |

## Platform (set up by `infra/`)

```
 Mac ──push──▶ registry (master :30500) ◀──pull── nodes (registries.yaml mirror)

 browser ──:443──▶ Traefik ──Gateway API──▶ public-gateway ──HTTPRoute──▶ typo3-frontend ×2
                                                                └─/typo3──▶ typo3-backend
 typo3 pods ──▶ typo3-mariadb (mariadb-operator)
            ──▶ typo3-valkey (sessions, locks) / typo3-valkey-cache (caches)
            ──▶ NFS on the master (fileadmin, typo3temp/assets)
            ──▶ garage (S3, storage 2) ◀──/media/── browser
```

| Component | Installed by | Notes |
|---|---|---|
| Registry | k3s manifest, `registry:3` on the master | plain HTTP. Every node has a containerd mirror for it |
| RWX storage | `nfs-kernel-server` on the master + `csi-driver-nfs` | StorageClass `nfs`, `reclaimPolicy: Retain` |
| Gateway API | k3s ships the CRDs; Traefik's provider is switched on | GatewayClass `traefik`, Gateway `gateway-system/public-gateway`: `https` for routes from namespaces labelled `gateway-access: public`, `http` only redirects |
| TLS | OpenTofu `tls` provider: cluster CA + certificate for all node IPs | Secret `gateway-tls`, also Traefik's default certificate (`TLSStore default`), because browsers send no SNI for IP addresses |
| mariadb-operator | HelmCharts `mariadb-operator-crds` + `mariadb-operator` | namespace `mariadb-system` |

## Why `settings.php` is prepared at build time

Benni's image has a read-only, environment-free `settings.php`: "TYPO3 never writes this file at runtime." On a fresh database, the migrations Job broke that rule twice:

1. `extension:setup` writes the default configuration of every extension that has an `ext_conf_template.txt`, whenever it is missing from `settings.php`.
2. `upgrade:run` applies TYPO3's silent configuration upgrades. The first one generates an encryption key whenever the **file** has none, even though `kubernetes.php` sets the real key from the environment.

`docker/prepare-settings.php` runs in the image build, boots TYPO3 without a database and applies both, until nothing is left to change. `docker/settings.php` carries the placeholder `set-from-env-TYPO3_ENCRYPTION_KEY`, so no real key is generated into the image. `kubernetes.php` always replaces the placeholder with `TYPO3_ENCRYPTION_KEY`.

## Why the first install is a separate pod

In TYPO3 v15, `extension:setup` authenticates the CLI user first, so it needs `be_users` to exist. It can update a database but not create one. The deploy script therefore runs `typo3 setup` once, in a pod with the same image, when `be_users` is missing. `setup` writes its own `settings.php`, so the pod gets a writable copy of `config/system` that is thrown away with the pod.

## Secrets

Created once by the deploy script, never stored in git or Helm:

| Secret | Keys |
|---|---|
| `typo3-env` | `TYPO3_ENCRYPTION_KEY`, `TYPO3_DB_PASSWORD`, `TYPO3_VALKEY_PASSWORD` (all TYPO3 pods via `envFrom`) |
| `typo3-mariadb` | `root-password`, `typo3-password` |
| `typo3-valkey` | `password` |
| `typo3-admin` | `username`, `password` of the backend admin |
| `typo3-garage` | Garage RPC secret, admin token, S3 access key and secret (the key pair is also in `typo3-env`) |

## Resource budget

Requests on the two workers (6 GiB each):

| Workload | CPU | Memory |
|---|---|---|
| frontend ×2 | 400m | 768 Mi |
| backend | 100m | 384 Mi |
| MariaDB | 500m | 1 Gi |
| Valkey + cache | 150m | 704 Mi |
| **total** | **1.15 CPU** | **≈ 2.9 GiB** |

Headlamp, the operator and the platform components come on top, so there is room for the HPA's two extra frontend pods.

## Not done yet

- **Public certificates:** the Gateway uses the cluster's own CA. For a public hostname, cert-manager with Let's Encrypt (as in Benni's example) would replace it.
- **Backups:** `examples/mariadb/02-backups.yaml` (mariadb-operator Backup CR) isn't applied.
- **High availability:** MariaDB, Valkey and Garage run as single instances.

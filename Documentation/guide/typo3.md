# TYPO3 on the cluster

The cluster runs a TYPO3 v15 site, built and deployed the way [Benni Mack's typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter) does it. The same production image and Helm chart run here, on a real 3-node cluster, with the database and caches inside the cluster.

| What | Where |
|---|---|
| Website | `https://192.168.105.200/` (every node IP works; `http://` redirects) |
| Backend | `https://192.168.105.200/typo3`, user `admin` |
| Health | `/_health/live`, `/_health/ready` |

## HTTPS

TLS ends at the Gateway, as in Benni's production setup. `tofu apply` creates a private CA for the cluster and a certificate for every node IP and hostname, valid for 397 days. Plain HTTP answers with a redirect to HTTPS.

Trust the CA once, and the browser stops warning:

```sh
# macOS: trust the cluster CA once (asks for your password)
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain infra/cluster-ca.crt
```

Firefox has its own certificate store: Settings → Privacy & Security → Certificates → View Certificates → Authorities → Import.

The admin password is in a Secret:

```sh
kubectl -n typo3 get secret typo3-admin -o jsonpath='{.data.password}' | base64 -d
```

## Deploy

Requirements: a cluster from `infra/` (`tofu apply`), plus `docker`, `kubectl`, `helm`, `jq` and `tofu` on your machine.

```sh
./k8s/deploy.sh
```

The script:

1. builds `docker/Dockerfile` for the nodes' architecture and pushes it to the cluster registry (`<master-ip>:30500`). The release then uses the image's **digest**, not a tag.
2. creates the namespace `typo3` and, the first time only, the Secrets with random passwords
3. applies `k8s/platform/` (MariaDB, two Valkey instances, two RWX volumes) and waits until they are ready
4. **empty database only:** runs `typo3 setup` in a one-off pod (schema and admin user), then imports the root page, About and Contact (`k8s/bootstrap.sql`)
5. runs `helm upgrade --install` with `k8s/charts/typo3` and `k8s/values/cluster.yaml`. Before the new pods start, the chart's migrations Job runs `extension:setup`, `upgrade:run` and `cache:flush --group pages`.

Running it again is safe: Secrets, the database and the pages are left as they are, and the new image rolls out without downtime.

```sh
./k8s/deploy.sh --no-build    # re-apply values/chart with the deployed image
```

::: tip macOS: kubectl blocked
If macOS blocks `kubectl` from reaching the VMs (see [Troubleshooting](./troubleshooting#no-route-to-host-from-kubectl-or-the-browser-while-ssh-and-ping-work)), the script switches to an SSH tunnel to the master by itself.
:::

## What runs

```sh
kubectl -n typo3 get pods -o wide
```

| Workload | Replicas | Role |
|---|---|---|
| `typo3-frontend` | 2 (HPA 2–4) | website, one pod per worker |
| `typo3-backend` | 1 | `/typo3` for editors, routed separately |
| `typo3-scheduler` | CronJob every 15 min | TYPO3 scheduler |
| `typo3-mariadb-0` | 1 | MariaDB 11.8 via mariadb-operator |
| `typo3-valkey-0` | 1 | sessions and locks (persistent) |
| `typo3-valkey-cache` | 1 | TYPO3 caches (in memory only) |

Files in `fileadmin/` and `typo3temp/assets/` are on NFS volumes that all pods share.

## Update the site

Change code, templates or `composer.lock`, then deploy:

```sh
./k8s/deploy.sh
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
helm -n typo3 uninstall typo3
kubectl delete namespace typo3
```

The NFS volumes use `reclaimPolicy: Retain`, so their files stay in `/srv/nfs` on the master until you delete them there. The next `./k8s/deploy.sh` installs a fresh site.

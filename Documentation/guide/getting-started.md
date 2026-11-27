# Getting started

The OpenTofu project in `infra/` creates three Ubuntu 24.04 VMs and turns them into a Kubernetes cluster:

| Node | Role | Default size |
|---|---|---|
| `k8s-master` | k3s server (control plane) | 2 vCPU, 4 GB RAM, 30 GB disk |
| `k8s-worker-1` | k3s agent | 2 vCPU, 4 GB RAM, 30–40 GB disk |
| `k8s-worker-2` | k3s agent | 2 vCPU, 4 GB RAM, 30–40 GB disk |

The master only runs the control plane. Your workloads run on the workers.

## 1. Prepare the host

Follow the page for your machine once:

- [macOS host](./macos): Apple Silicon Mac
- [Odroid H2 host](./odroid): x86_64 Linux with KVM
- [Raspberry Pi 5 host](./raspberry-pi5): arm64 Linux with KVM (prepared, untested)

## Your settings

Settings live in two places:

- **`kate.yaml` in the repository root**: what is yours and cluster-wide (the Hetzner DNS token, the Let's Encrypt contact, extra SSH keys, Helm values for every site). Git ignores it.
- **`projects/<name>/kate.yaml`**: one per site, no secrets: its domain, the Let's Encrypt environment, the namespace and its own Helm values. See [TYPO3 on the cluster](./typo3#sites-in-projects).

```sh
cp kate.example.yaml kate.yaml && chmod 600 kate.yaml
```

| Section of `kate.yaml` | Read by | |
|---|---|---|
| `acme` (`email`, `providers`, `defaultProvider`) | OpenTofu (`infra/`) | Let's Encrypt issuers and the DNS providers (Hetzner, Cloudflare, Route 53, DigitalOcean, RFC2136) for every site's certificate; win over the `*.tfvars` and `TF_VAR_*` |
| `acme.wildcard` (`domain`, `environment`) | `./kate deploy`, `./kate dns` | one certificate for `*.<domain>`, shared by the sites below it |
| `ssh.authorizedKeys` | OpenTofu (`infra/`) | more public keys for `ssh ubuntu@<node>` |
| `helm` | `./kate deploy` | any value of `k8s/charts/typo3`, for every site, applied after `k8s/values/cluster.yaml` |

Every setting of both files, with defaults and full examples: [Settings (kate.yaml)](../reference/settings).

Each site sets its domain and Let's Encrypt environment in its own `projects/<name>/kate.yaml`; the root `kate.yaml` has no such settings.

Without `kate.yaml` everything runs on IP addresses with the cluster CA. The `*.tfvars` keep describing the host (CPU, RAM, network), `k8s/values/cluster.yaml` the defaults for this cluster.

## 2. Create the cluster

```sh
cd infra
tofu init
tofu apply -var-file=macos.tfvars -parallelism=1   # or: -var-file=odroid.tfvars
```

`tofu apply` shows a plan. Type `yes` to build. The first run downloads the Ubuntu cloud image (about 600 MB), so it takes about 5–10 minutes. Then it waits until:

1. SSH on the master answers
2. the Kubernetes API is ready
3. all 3 nodes are `Ready`
4. the dashboard has rolled out

::: warning macOS: keep `-parallelism=1`
Homebrew's libvirtd 12.9 crashes when several VMs are created at the same time. See [Troubleshooting](./troubleshooting#libvirtd-crashes-procedure-interrupted).
:::

## 3. Use it

```sh
export KUBECONFIG=$PWD/kubeconfig
kubectl get nodes

tofu output dashboard_url                     # http://<master-ip>:30080
$(tofu output -raw dashboard_token_command)   # paste the token into Headlamp
```

More on this in [Dashboard & kubectl](./dashboard). Then deploy a site from the repository root, `./kate deploy kate`: see [TYPO3 on the cluster](./typo3#deploy).

## 4. Remove it

```sh
tofu destroy -var-file=macos.tfvars -parallelism=1
```

See [Day-to-day operations](./operations) for rebuilding, resizing and removing only the VMs.

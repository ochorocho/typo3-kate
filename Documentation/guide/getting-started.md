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

What is yours and not the repository's (the domain, the Hetzner DNS token, Let's Encrypt, any Helm value you want different) goes into one file, `kate.yaml` in the repository root. Git ignores it.

```sh
cp kate.example.yaml kate.yaml && chmod 600 kate.yaml
```

| Section | Read by | |
|---|---|---|
| `domain`, `acme` | OpenTofu (`infra/`) | certificate on the Gateway; win over the `*.tfvars` and `TF_VAR_*` |
| `domain` | `k8s/deploy.sh` | media and Mercure URLs; without it they use the master IP |
| `helm` | `k8s/deploy.sh` | any value of `k8s/charts/typo3`, applied after `k8s/values/cluster.yaml` |

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

More on this in [Dashboard & kubectl](./dashboard).

## 4. Remove it

```sh
tofu destroy -var-file=macos.tfvars -parallelism=1
```

See [Day-to-day operations](./operations) for rebuilding, resizing and removing only the VMs.

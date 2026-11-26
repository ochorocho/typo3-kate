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

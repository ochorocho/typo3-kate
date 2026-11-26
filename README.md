# typo3-kate

[![ci](https://github.com/ochorocho/typo3-kate/actions/workflows/ci.yml/badge.svg)](https://github.com/ochorocho/typo3-kate/actions/workflows/ci.yml)
[![docs](https://github.com/ochorocho/typo3-kate/actions/workflows/docs.yml/badge.svg)](https://ochorocho.github.io/typo3-kate/)

A 3-node Kubernetes cluster on your own machine, running TYPO3 v15.

- **`infra/`**: OpenTofu creates three Ubuntu VMs on libvirt (1 master, 2 workers) and installs k3s, the Headlamp dashboard, an image registry, shared NFS storage, Gateway API routing and the MariaDB operator. Works on an Apple Silicon Mac (QEMU + Hypervisor.framework) and on an Odroid H2 (KVM).
- **TYPO3:** the production image, Helm chart and configuration from [Benni Mack's typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter), deployed with MariaDB, Valkey and shared storage inside the cluster.

📖 **Documentation:** https://ochorocho.github.io/typo3-kate/

## Quick start (Apple Silicon Mac)

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/), an SSH key at `~/.ssh/id_ed25519.pub` and about 16 GB of free RAM.

**1. Install the tools and start the services** (once):

```sh
brew install opentofu libvirt qemu socket_vmnet cdrtools helm jq
brew services start libvirt
sudo brew services start socket_vmnet     # run in a normal terminal, it asks for your password
```

**2. Create the cluster** (about 10 minutes the first time):

```sh
cd infra
tofu init
tofu apply -var-file=macos.tfvars -parallelism=1
cd ..
```

**3. Deploy TYPO3** (about 5 minutes the first time):

```sh
./scripts/typo3-deploy.sh
```

**4. Open it:**

| What | URL |
|---|---|
| Website | http://192.168.105.200/ |
| TYPO3 backend | http://192.168.105.200/typo3 (user `admin`) |
| Kubernetes dashboard | http://192.168.105.200:30080 |

```sh
export KUBECONFIG=$PWD/infra/kubeconfig
kubectl -n typo3 get secret typo3-admin -o jsonpath='{.data.password}' | base64 -d; echo   # backend password
infra/scripts/dashboard-token.sh 192.168.105.200                                            # dashboard token
```

> **"no route to host"?** macOS blocks local network access per app. Allow your terminal and browser under
> System Settings → Privacy & Security → Local Network. The deploy script falls back to an SSH tunnel by itself.

## Quick start (Odroid H2 / Linux with KVM)

```sh
sudo apt install -y qemu-kvm libvirt-daemon-system genisoimage
sudo usermod -aG libvirt,kvm "$USER"      # log out and back in
# install OpenTofu, Docker, helm and jq

cd infra && tofu init && tofu apply -var-file=odroid.tfvars && cd ..
./scripts/typo3-deploy.sh
```

The site is then on http://10.17.3.10/. To reach it from other machines, put the VMs on your LAN: see [Odroid H2 host](https://ochorocho.github.io/typo3-kate/guide/odroid).

## Everyday commands

```sh
./scripts/typo3-deploy.sh                            # build and roll out a new version
helm -n typo3 rollback typo3 <revision>              # go back to an earlier release
kubectl -n typo3 logs deploy/typo3-frontend -f       # logs
cd infra && tofu destroy -var-file=macos.tfvars -parallelism=1   # remove everything
```

## Layout

```
infra/                OpenTofu: VMs, k3s and the cluster platform
charts/typo3/         Helm chart (from typo3-k8s-starter)
docker/               production image (FrankenPHP, PHP 8.5)
config/, packages/    TYPO3 project: configuration from env vars, health checks, site package
values/cluster.yaml   Helm values for this cluster
platform/             MariaDB, Valkey and volumes for the typo3 namespace
scripts/              typo3-deploy.sh
Documentation/        VitePress docs (npm run dev)
```

## Credits

The TYPO3 project, image and Helm chart are from [bmack/typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter) by Benni Mack, MIT licensed (see [LICENSE-typo3-k8s-starter](LICENSE-typo3-k8s-starter)). What was changed and why: [TYPO3 architecture](https://ochorocho.github.io/typo3-kate/reference/typo3-architecture).

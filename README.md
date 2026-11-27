# typo3-kate

[![ci](https://github.com/ochorocho/typo3-kate/actions/workflows/ci.yml/badge.svg)](https://github.com/ochorocho/typo3-kate/actions/workflows/ci.yml)
[![docs](https://github.com/ochorocho/typo3-kate/actions/workflows/docs.yml/badge.svg)](https://ochorocho.github.io/typo3-kate/)

A 3-node Kubernetes cluster on your own machine, running one or more TYPO3 v15 sites.

- **`infra/`**: OpenTofu creates three Ubuntu VMs on libvirt (1 master, 2 workers) and installs k3s, the Headlamp dashboard, an image registry, shared NFS storage, Gateway API routing and the MariaDB operator. Works on an Apple Silicon Mac (QEMU + Hypervisor.framework) and on an Odroid H2 (KVM); a Raspberry Pi 5 configuration is prepared but not yet tested on hardware.
- **TYPO3:** the production image, Helm chart and configuration from [Benni Mack's typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter), deployed with MariaDB, Valkey, S3 (Garage) and shared storage inside the cluster. Each site is a folder in `projects/` with its own namespace, image, domain and certificate.

📖 **Documentation:** https://ochorocho.github.io/typo3-kate/

## Quick start (Apple Silicon Mac)

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/), an SSH key at `~/.ssh/id_ed25519.pub` and about 16 GB of free RAM.

**1. Install the tools and start the services** (once):

```sh
brew install opentofu libvirt qemu socket_vmnet cdrtools helm jq yq gum
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
./kate deploy kate
```

**4. Open it:**

| What | URL |
|---|---|
| Website | https://192.168.105.200/ |
| TYPO3 backend | https://192.168.105.200/typo3 (user `admin`) |
| Kubernetes dashboard | http://192.168.105.200:30080 |
| Public name (optional) | https://kate.ochorocho.dev/ with Let's Encrypt, see [Public domain & Let's Encrypt](https://ochorocho.github.io/typo3-kate/guide/public-access) |

The certificate comes from a CA that `tofu apply` creates for the cluster. Until you trust it, the browser warns once:

```sh
# macOS: trust the cluster CA once (asks for your password)
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain infra/cluster-ca.crt
```

```sh
export KUBECONFIG=$PWD/infra/kubeconfig
./kate password kate                                                         # backend user and password (--copy: clipboard)
infra/scripts/dashboard-token.sh 192.168.105.200                                            # dashboard token
```

> **"no route to host"?** macOS blocks local network access per app. Allow your terminal and browser under
> System Settings → Privacy & Security → Local Network. The deploy script falls back to an SSH tunnel by itself.

## Quick start (Odroid H2 / Linux with KVM)

```sh
sudo apt install -y qemu-kvm libvirt-daemon-system genisoimage
sudo usermod -aG libvirt,kvm "$USER"      # log out and back in
# install OpenTofu, Docker, helm, jq, yq and gum

cd infra && tofu init && tofu apply -var-file=odroid.tfvars && cd ..
./kate deploy kate
```

The site is then on https://10.17.3.10/ (CA in `infra/cluster-ca.crt`). To reach it from other machines, put the VMs on your LAN: see [Odroid H2 host](https://ochorocho.github.io/typo3-kate/guide/odroid).

## Raspberry Pi 5 (prepared, untested)

`infra/raspberrypi5.tfvars` targets an 8 GB Pi 5 with Ubuntu Server: KVM guests with GICv2 (the Pi's interrupt controller) and UEFI from `qemu-efi-aarch64`. The host needs a 4K-page kernel. See [Raspberry Pi 5 host](https://ochorocho.github.io/typo3-kate/guide/raspberry-pi5).

## Everyday commands

```sh
./kate                                             # menu: deploy, new, status, remove (needs gum)
./kate deploy kate                                 # build and roll out a new version of one site
./kate deploy --all                                # every site in projects/ except kate
./kate new shop --domain shop.example.com          # a new site from the template
./kate status                                      # every site and its state
./kate password kate --copy                        # backend password to the clipboard
./kate autocomplete                                # tab completion for zsh or bash (once)
helm -n typo3 rollback typo3 <revision>              # go back to an earlier release
kubectl -n typo3 logs deploy/typo3-frontend -f       # logs
cd infra && tofu destroy -var-file=macos.tfvars -parallelism=1   # remove everything
```

## Layout

```
projects/<name>/       one TYPO3 site each: kate.yaml, composer.json/.lock, config/sites/
├── kate/              the kate site (namespace typo3, kate.ochorocho.dev)
└── _example/          template for a new site (folders starting with _ are skipped)
infra/                 OpenTofu: VMs, k3s and the cluster platform
kate                   ./kate deploy | new | status | remove (gum menu without arguments)
k8s/                   everything that runs on the cluster
├── charts/typo3/      Helm chart (from typo3-k8s-starter)
├── values/cluster.yaml  Helm values for this cluster
├── platform/          MariaDB, Valkey, Garage (S3) and volumes, per site namespace
└── bootstrap.sql      first pages for an empty database
docker/                production image (FrankenPHP, PHP 8.5), built per site
config/system/         shared by all sites: configuration from env vars
packages/              shared by all sites: health checks, site package
kate.example.yaml      template for kate.yaml: cluster-wide settings (git-ignored)
Documentation/         VitePress docs (npm run dev)
```

## Credits

The TYPO3 project, image and Helm chart are from [bmack/typo3-k8s-starter](https://github.com/bmack/typo3-k8s-starter) by Benni Mack, MIT licensed (see [LICENSE-typo3-k8s-starter](LICENSE-typo3-k8s-starter)). What was changed and why: [TYPO3 architecture](https://ochorocho.github.io/typo3-kate/reference/typo3-architecture).

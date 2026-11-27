# 3-node Kubernetes cluster on libvirt (macOS + Odroid H2)

OpenTofu creates three Ubuntu 24.04 VMs (1 master, 2 workers). When `tofu apply` finishes you have:

- a working **k3s** Kubernetes cluster (with Traefik ingress, CoreDNS, metrics-server and local-path storage)
- the **Headlamp** dashboard on `http://<master-ip>:30080`, with an admin login token
- a `kubeconfig` file next to this README, ready to use with `kubectl`
- static IPs, `/etc/hosts` entries and your SSH key on every node

| Host            | Hypervisor             | Network                             | Preset          |
|-----------------|------------------------|-------------------------------------|-----------------|
| Apple Silicon   | QEMU + HVF via libvirt | socket_vmnet (`192.168.105.200-202`) | `macos.tfvars`  |
| Odroid H2 (x86) | KVM via libvirt        | NAT `10.17.3.10-12` or LAN bridge   | `odroid.tfvars` |

Full documentation: [ochorocho.github.io/typo3-kate](https://ochorocho.github.io/typo3-kate/), source in [`../Documentation`](../Documentation) (VitePress: `cd Documentation && npm install && npm run dev`).

macOS has no KVM. libvirt runs the VMs with QEMU and Apple's Hypervisor.framework (`hvf`), which is just as fast.

## macOS setup (once)

```sh
brew install opentofu libvirt qemu socket_vmnet cdrtools   # cdrtools = mkisofs for cloud-init
brew services start libvirt
# socket_vmnet needs root to use Apple's vmnet
sudo brew services start socket_vmnet
```

## Odroid H2 setup (once, Ubuntu/Debian host)

```sh
sudo apt install -y qemu-kvm libvirt-daemon-system genisoimage   # genisoimage = mkisofs for cloud-init
sudo usermod -aG libvirt,kvm "$USER"   # log out and back in afterwards
# install OpenTofu: https://opentofu.org/docs/intro/install/
```

To manage the Odroid from your Mac, set `libvirt_uri = "qemu+ssh://<user>@<odroid>/system"` in `odroid.tfvars`. Use `network_mode = "bridge"` in that case, so the VMs get LAN IPs your Mac can reach.

## Usage

```sh
tofu init
tofu apply -var-file=macos.tfvars -parallelism=1   # or: -var-file=odroid.tfvars
```

On macOS keep `-parallelism=1`: Homebrew's libvirtd (12.9) crashes when several VMs are created at the same time.

The first run downloads the Ubuntu image and waits for the cluster (about 5-10 min). After that:

```sh
export KUBECONFIG=$PWD/kubeconfig
kubectl get nodes

tofu output dashboard_url
$(tofu output -raw dashboard_token_command)   # paste the token into Headlamp
```

`tofu destroy -var-file=... -parallelism=1` removes everything again.

## Customising

All options are in `variables.tf`. Common ones:

- `worker_count`, `master`, `worker`: node count and size (vCPU / RAM / disk)
- `master_schedulable = true`: also run workloads on the master
- `k3s_channel`: e.g. `"v1.34"` to pin a Kubernetes minor
- `ssh_public_key_path`: defaults to `~/.ssh/id_ed25519.pub`; installed by cloud-init, so changing it rebuilds the VMs
- `ssh.authorizedKeys` in `../kate.yaml`: more public keys, e.g. your laptop's; added over SSH on every apply, without rebuilding the VMs
- `acme:` in `../kate.yaml`: the Let's Encrypt ClusterIssuers (DNS-01 on Hetzner) that every site's certificate uses; the token is then needed on every run. Domains are per site, in `../projects/<name>/kate.yaml`, and `k8s/deploy.sh` creates each site's Gateway and certificate
- `wait_for_cluster = false`: return as soon as the VMs are started

## Troubleshooting

- `ssh ubuntu@<ip> 'cloud-init status --long'` shows how far the setup has got.
- `virsh -c qemu:///session console k8s-master` (macOS) or `virsh console k8s-master` (Linux) opens the serial console.
- macOS: if the VMs get no network, check that `ls -l /opt/homebrew/var/run/socket_vmnet` exists and that the socket_vmnet service runs as root.
- Ubuntu host: "Permission denied" on the disk images usually comes from AppArmor. Set `security_driver = "none"` in `/etc/libvirt/qemu.conf` and restart libvirtd.
- macOS: `kubectl` or `curl` report "no route to host" while `ping`/`ssh` work → macOS is blocking local network access for that app. Enable your terminal (and browser) under System Settings → Privacy & Security → Local Network.

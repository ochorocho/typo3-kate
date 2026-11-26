# Files

```
infra/
├── versions.tf                    OpenTofu and provider versions, provider setup
├── variables.tf                   all settings (see Variables)
├── main.tf                        pool, images, network, cloud-init, VMs, readiness
├── outputs.tf                     IPs, SSH commands, dashboard URL, kubeconfig
├── macos.tfvars                   preset: Apple Silicon + socket_vmnet
├── odroid.tfvars                  preset: Odroid H2, NAT or bridge
├── templates/
│   ├── user-data.yaml.tftpl       cloud-init: user, packages, /etc/hosts, k3s config + install,
│   │                              registry mirror, NFS export, master manifests
│   ├── registry.yaml.tftpl        image registry on the master (NodePort 30500)
│   ├── storage.yaml.tftpl         csi-driver-nfs + StorageClass nfs
│   ├── gateway.yaml.tftpl         Traefik Gateway API provider, GatewayClass, public-gateway
│   ├── mariadb-operator.yaml.tftpl  mariadb-operator (CRDs + operator)
│   ├── network-config.yaml.tftpl  netplan: static IP on lan0, matched by MAC
│   ├── dashboard.yaml.tftpl       Headlamp HelmChart + admin ServiceAccount/token
│   └── domain.xsl.tftpl           libvirt XML patches (aarch64, socket_vmnet)
├── scripts/
│   ├── wait-for-cluster.sh        waits for nodes + dashboard, writes kubeconfig
│   └── dashboard-token.sh         prints the dashboard login token
└── README.md                      short version of this documentation
```

Generated, and ignored by git:

| File | Contents |
|---|---|
| `.terraform/` | downloaded providers |
| `terraform.tfstate*` | OpenTofu state, **including the k3s token** |
| `kubeconfig` | admin credentials for the cluster |

`.terraform.lock.hcl` pins the exact provider builds. Commit it.

## TYPO3 (repository root)

```
composer.json, composer.lock   TYPO3 v15 project (from bmack/typo3-k8s-starter)
config/system/                 configuration from environment variables
config/sites/main/             site configuration (versioned, baked into the image)
packages/                      k8s-health (health checks), k8s-starter (site package)
docker/                        production image; prepare-settings.php runs at build time
charts/typo3/                  Helm chart (unchanged from upstream)
values/cluster.yaml            values for this cluster
platform/                      MariaDB, Valkey, RWX volumes for namespace typo3
build/bootstrap.sql            first pages for an empty database
scripts/typo3-deploy.sh        build, push, deploy
LICENSE-typo3-k8s-starter      MIT license of the upstream project
```

See [TYPO3 architecture](./typo3-architecture).

## Documentation

```
Documentation/
├── .vitepress/config.mts   site config, navigation
├── index.md                start page
├── guide/                  how-to pages
└── reference/              variables, architecture, files
```

```sh
cd Documentation
npm install
npm run dev       # http://localhost:5173
npm run build     # static site in .vitepress/dist
```

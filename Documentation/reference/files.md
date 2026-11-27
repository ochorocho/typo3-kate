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
│   ├── gateway.yaml.tftpl         Traefik Gateway API provider, GatewayClass, public-gateway (node IPs)
│   ├── mariadb-operator.yaml.tftpl  mariadb-operator (CRDs + operator)
│   ├── cert-manager.yaml.tftpl    cert-manager + Hetzner DNS webhook
│   ├── acme.yaml.tftpl            Let's Encrypt ClusterIssuers, token Secret
│   ├── network-config.yaml.tftpl  netplan: static IP on lan0, matched by MAC
│   ├── dashboard.yaml.tftpl       Headlamp HelmChart + admin ServiceAccount/token
│   └── domain.xsl.tftpl           libvirt XML patches (aarch64, socket_vmnet)
├── scripts/
│   ├── wait-for-cluster.sh        waits for the nodes, writes kubeconfig
│   ├── push-manifests.sh          copies the platform manifests to the master, waits for them
│   └── dashboard-token.sh         prints the dashboard login token
└── README.md                      short version of this documentation
```

Generated, and ignored by git:

| File | Contents |
|---|---|
| `.terraform/` | downloaded providers |
| `terraform.tfstate*` | OpenTofu state, **including the k3s token, the CA's private key and the DNS token** |
| `kubeconfig` | admin credentials for the cluster |
| `cluster-ca.crt` | the cluster CA (public). Trust it to avoid browser warnings |
| `.manifests/` | rendered platform manifests, **including the Gateway key and the DNS token** |

`.terraform.lock.hcl` pins the exact provider builds. Commit it.

## TYPO3 (repository root)

```
projects/<name>/               one site each
├── kate.yaml                  domain, acme.environment, namespace, nodeIPs, helm: (committed, no secrets)
├── composer.json, composer.lock  the site's packages; path repository ../../packages/*
├── config/sites/              site configuration (versioned, baked into the image)
└── packages/                  optional: packages of this site only
projects/kate/                 the kate site (from bmack/typo3-k8s-starter), namespace typo3
projects/_example/kate.yaml    template for a new site; folders starting with _ are skipped by --all
config/system/                 shared: configuration from environment variables (a site has none of its own)
packages/                      shared: k8s-health (health checks), k8s-starter (site package)
docker/                        production image, built per site (--build-arg PROJECT=<name>);
                               prepare-settings.php runs at build time
k8s/                           everything that runs on the cluster
├── deploy.sh                  build, push, deploy: deploy.sh <name> [--no-build] | --all
├── remove.sh                  remove a site: release, namespace, released NFS volumes
├── site-values.sh             Helm values from a site's URL and domain (media, Mercure, routes)
├── project-manifests.sh       a site's Gateway, Certificate and /media/ route
├── charts/typo3/              Helm chart (unchanged from upstream)
├── values/cluster.yaml        values for this cluster
├── platform/                  MariaDB, Valkey, Garage (S3), RWX volumes, per site namespace
└── bootstrap.sql              first pages for an empty database
kate.example.yaml              template for kate.yaml (git-ignored): ACME token, SSH keys, helm: for every site
LICENSE-typo3-k8s-starter      MIT license of the upstream project
.github/workflows/ci.yml       lint (PHP, shell, Helm, kubeconform), OpenTofu, image + smoke test
.github/workflows/docs.yml     builds this documentation, publishes it to GitHub Pages
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

Every push to `main` that touches `Documentation/` publishes the site to
[ochorocho.github.io/typo3-kate](https://ochorocho.github.io/typo3-kate/).

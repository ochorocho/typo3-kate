# Troubleshooting

Every problem on this page came up while building the macOS setup. The fixes are already in the configuration. The entries help you spot the same problem again.

## First checks

```sh
ssh ubuntu@<ip> 'cloud-init status --long'          # did the setup finish?
ssh ubuntu@<master-ip> 'sudo journalctl -u k3s -n 50'
ssh ubuntu@<worker-ip> 'sudo journalctl -u k3s-agent -n 50'
ssh ubuntu@<master-ip> 'sudo k3s kubectl get pods -A'
```

## `dial unix /var/run/libvirt/libvirt-sock: no such file`

**macOS.** The libvirt service is not running, or the provider is looking for the system socket.

```sh
brew services start libvirt
ls ~/.cache/libvirt/libvirt-sock
```

The configuration already passes `?socket=~/.cache/libvirt/libvirt-sock` in the URI, because provider 0.8.3 ignores where the session socket lives.

## `exec: "mkisofs": executable file not found`

The provider builds the cloud-init ISO with an external tool.

- macOS: `brew install cdrtools`
- Ubuntu/Debian: `sudo apt install genisoimage`

## libvirtd crashes: `procedure interrupted`

**macOS.** Homebrew's libvirtd 12.9 segfaults in `virConnectGetCapabilities` when several VMs are created at the same time. A crash report appears in `~/Library/Logs/DiagnosticReports/libvirtd-*.ips`.

Always run `tofu apply` and `tofu destroy` with `-parallelism=1` on macOS. If a run failed this way, libvirt may still have the domains defined without OpenTofu knowing about them. Remove them before you retry:

```sh
virsh -c qemu:///session list --all
virsh -c qemu:///session undefine k8s-master --nvram   # for each leftover VM
```

## `HVF does not support GICv2 emulation`

**macOS, aarch64.** Apple's hypervisor only works with the GICv3 interrupt controller. The XSLT patch adds `<gic version="3"/>`. If you see this, the patch was not applied: check that `arch = "aarch64"`.

## `PCI: slot 1 function 0 not available`

The socket_vmnet network card is added as a raw QEMU argument. Without a fixed address it takes PCI slot 1, which libvirt needs. The patch pins it to `bus=pcie.0,addr=0x1f`.

## VM is running but unreachable

1. Is socket_vmnet running? `ls -l /opt/homebrew/var/run/socket_vmnet`
2. Is the VM connected? `tail /opt/homebrew/var/log/socket_vmnet/stderr` should show one `Accepted a connection` per running VM.
3. Did the VM boot? Watch the serial console: `virsh -c qemu:///session console <vm>`. The `ci-info` table shows `lan0` with its IP.
4. Do packets arrive? `netstat -I bridge100 -b` shows the in/out counters on the Mac side.

On the very first run, the VMs once had a correct network config but got no traffic through. Recreating them fixed it, and it hasn't happened since. If it happens again, rebuild the VMs (see [Operations](./operations#rebuild-from-scratch)).

## `no route to host` from kubectl or the browser, while ssh and ping work

**macOS Local Network privacy.** macOS blocks the app until you allow it under **System Settings → Privacy & Security → Local Network**. Apple's own tools (`ssh`, `ping`, `nc`, `/usr/bin/curl`) are exempt. Homebrew's `curl`, `kubectl` and browsers are not.

Quick test:

```sh
/usr/bin/curl -s -o /dev/null -w '%{http_code}\n' http://<master-ip>:30080   # 200 = cluster is fine
```

## Dashboard never comes up: `helm-install-headlamp` in CrashLoopBackOff

```sh
ssh ubuntu@<master-ip> 'sudo k3s kubectl -n kube-system logs job/helm-install-headlamp --tail=20'
```

A message like `ClusterRoleBinding "…" exists and cannot be imported into the current release` means an object in `templates/dashboard.yaml.tftpl` has the same name as one the Headlamp chart creates. The chart owns `headlamp` and `headlamp-admin`, so our login account is called `dashboard-admin`.

Other causes: no internet in the VMs (the chart is downloaded from `kubernetes-sigs.github.io`), or no worker is `Ready` yet. The master is tainted, so the dashboard can only run on a worker.

## Changed a setting but the VM ignores it

cloud-init only runs on a node's first boot. The configuration replaces a node's root disk whenever its cloud-init ISO changes (`replace_triggered_by`). If you changed something outside OpenTofu, rebuild the node.

## Ubuntu host: `Permission denied` on disk images

That is AppArmor. Set `security_driver = "none"` in `/etc/libvirt/qemu.conf`, then run `sudo systemctl restart libvirtd`.

## `wait-for-cluster.sh` timed out

The script gives up after 20 minutes (`TIMEOUT=1200`). The VMs keep running. Find the cause with the first checks above, then fetch the kubeconfig by hand:

```sh
MASTER_IP=<master-ip> EXPECTED_NODES=3 ./scripts/wait-for-cluster.sh
```

## TYPO3: migrations Job fails

```sh
./kate logs kate migrations --tail 30
```

| Message | Cause |
|---|---|
| `Table 'typo3.be_users' doesn't exist` | the database is empty: `extension:setup` can't install TYPO3. The deploy script runs `typo3 setup` first when `be_users` is missing. Check that step's output. |
| `config/system/settings.php is not writable` | `settings.php` in the image is missing something TYPO3 wants to add. `docker/prepare-settings.php` adds it at build time: rebuild with `./kate deploy kate`. See [TYPO3 architecture](../reference/typo3-architecture#why-settings-php-is-prepared-at-build-time). |

After a failed first install, Helm keeps a failed release. Remove it before you retry:

```sh
helm -n typo3 uninstall typo3
```

## TYPO3: page shows an error or 503

```sh
kubectl -n typo3 get pods
curl --cacert infra/cluster-ca.crt https://<node-ip>/_health/ready          # {"status":"ok","checks":{"database":true,"valkey":true}}
./kate logs kate frontend --tail 50
```

`/_health/ready` shows which dependency is missing. A pod that fails it gets no traffic.

## HTTPS: browser warns about the certificate

The Gateway's certificate comes from the cluster's own CA. Trust `infra/cluster-ca.crt` once (see [TYPO3 on the cluster](./typo3#https)). Restart the browser afterwards.

Check what the Gateway serves:

```sh
curl --cacert infra/cluster-ca.crt -sS -o /dev/null -w '%{http_code}\n' https://192.168.105.200/
openssl s_client -connect 192.168.105.200:443 </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer
```

The issuer must be `k8s cluster CA`. `TRAEFIK DEFAULT CERT` means the `TLSStore` named `default` in `kube-system` is missing: browsers send no server name (SNI) for IP addresses, so Traefik needs our certificate as its default.

## HTTPS: "404 page not found"

Traefik answers this when no route matches. A site's routes attach to its own Gateway `site` (its domain) and, for the one site on the node IPs, to the `https` listener of `public-gateway`; `http://` only redirects:

```sh
kubectl -n typo3 get gateway site \
  -o jsonpath='{range .status.listeners[*]}{.name}: {.attachedRoutes} routes{"\n"}{end}'
kubectl -n gateway-system get gateway public-gateway \
  -o jsonpath='{range .status.listeners[*]}{.name}: {.attachedRoutes} routes{"\n"}{end}'
kubectl -n typo3 get httproute typo3 media -o jsonpath='{range .items[*]}{.metadata.name}: {.status.parents[*].conditions[*].reason}{"\n"}{end}'
```

`https: 0 routes` on `public-gateway` means the node-IP site's namespace lacks the label `gateway-access: public`, which `./kate deploy` sets for a site with `nodeIPs: true` or without a domain. No Gateway `site` means the site has no `domain` in its `projects/<name>/kate.yaml`, or wasn't deployed since.

## Let's Encrypt: certificate not issued

```sh
kubectl -n typo3 describe certificate site | tail -15
kubectl get orders,challenges -A
kubectl -n cert-manager logs deploy/cert-manager-webhook-hetzner --tail=30
```

| Symptom | Cause |
|---|---|
| challenge `pending` with an authentication error | wrong token, or one from the old DNS Console. Create a read+write token in the Hetzner Console |
| `zone not found` | the zone isn't managed in the Hetzner Console, or not in the token's project |
| `too many failed authorizations` | Let's Encrypt rate limit: wait an hour, test with `acme.environment: staging` in the site's `kate.yaml` |
| browser warns although the certificate is `Ready` | still the staging certificate: set `acme.environment: production` in `projects/<name>/kate.yaml` and deploy again |
| `clusterissuer.cert-manager.io "letsencrypt" not found` | no `acme:` section in the root `kate.yaml` yet: add it and run `tofu apply` in `infra/` |

`./kate deploy` waits up to 10 minutes for the certificate and prints the challenges when it gives up.

## `tofu apply`: "Let's Encrypt is on (acme: in kate.yaml), so the Hetzner DNS token is needed"

On purpose: without the token the run would remove the DNS Secret and the issuers, and no site's certificate would renew. Fetch it from the cluster and run again:

```sh
export TF_VAR_hetzner_dns_token="$(ssh ubuntu@192.168.105.200 "sudo k3s kubectl -n cert-manager get secret hetzner -o jsonpath='{.data.token}' | base64 -d")"
```

## TYPO3: "Media (S3)" shows as offline

TYPO3 keeps storage 2 offline while any `TYPO3_S3_*` variable is missing, or when it can't reach the bucket.

```sh
kubectl -n typo3 exec deploy/typo3-backend -- printenv | grep TYPO3_S3_ | sed 's/\(SECRET=\).*/\1…/'
kubectl -n typo3 get pod garage-0
kubectl -n typo3 exec garage-0 -- /garage bucket info media
```

| Symptom | Cause |
|---|---|
| `TYPO3_S3_KEY` / `TYPO3_S3_SECRET` missing | `typo3-env` from before Garage: run `./kate deploy kate`, it adds them |
| storage still offline after the variables are there | the frontend/backend pods started before the change: `kubectl -n typo3 rollout restart deploy/typo3-backend deploy/typo3-frontend` |
| timeouts to `garage:3900` | the NetworkPolicy `typo3-to-garage` is missing: `kubectl -n typo3 get networkpolicy` |
| images in the page don't load (404) | `/media/` route or website mode: `kubectl -n typo3 get httproute media`, `… /garage bucket website --allow media` |

## `./kate deploy` refuses to deploy a site

It checks the other sites before it builds anything:

| Message | Cause |
|---|---|
| `namespace … belongs to project …` | two sites use the same `namespace`: set another one in `projects/<name>/kate.yaml` |
| `… is already the domain of …` | two sites have the same `domain` |
| `only one project can answer on the node IPs` | another site has `nodeIPs: true` or no domain. Give this one a `domain` |

What a namespace belongs to is in its annotations: `kubectl get namespace typo3 -o jsonpath='{.metadata.annotations}'`.

## `tofu apply`: "domain and acme.environment in kate.yaml are ignored now"

A warning: these settings moved to `projects/<name>/kate.yaml`. Remove them from the root `kate.yaml`. On a cluster that still has the old `https-domain` listener, follow [the migration](./public-access#migrating-from-the-domain-in-infra) first.

## Raspberry Pi 5: VMs don't start

See the table on the [Raspberry Pi 5 page](./raspberry-pi5#if-the-vms-don-t-start). The two Pi-specific causes are a 16K-page host kernel (`getconf PAGESIZE` must print `4096`) and a GICv3 request (the Pi only has GICv2; leave `gic_version` unset).

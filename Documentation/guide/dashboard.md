# Dashboard & kubectl

## Headlamp

[Headlamp](https://headlamp.dev) is the dashboard the Kubernetes project now recommends. The old Kubernetes Dashboard has been retired. With Headlamp you can browse and edit every resource, read logs, open a shell in a pod, scale deployments and see CPU and memory use.

```sh
cd infra
tofu output dashboard_url      # e.g. http://192.168.105.200:30080
```

The dashboard is a NodePort service, so it answers on port `30080` on **every** node.

### Log in

Headlamp asks for a token. Print it with:

```sh
./scripts/dashboard-token.sh <master-ip>
# or
$(tofu output -raw dashboard_token_command)
```

The token belongs to the ServiceAccount `dashboard-admin` in the `headlamp` namespace, which is bound to `cluster-admin`. It doesn't expire. Anyone with the token has full control of the cluster, so don't share it.

## kubectl

`tofu apply` writes `infra/kubeconfig`, which points at the master's IP:

```sh
export KUBECONFIG=/path/to/infra/kubeconfig
kubectl get nodes -o wide
kubectl top nodes
```

To fetch it again, for example after `wait_for_cluster = false`:

```sh
MASTER_IP=192.168.105.200 EXPECTED_NODES=3 ./scripts/wait-for-cluster.sh
```

You can also run kubectl on the master itself:

```sh
ssh ubuntu@<master-ip>
sudo k3s kubectl get pods -A
```

## What's in the cluster

| Component | Purpose |
|---|---|
| k3s (latest `stable` channel) | Kubernetes distribution |
| Flannel (VXLAN) | pod network on `lan0` |
| CoreDNS | cluster DNS |
| Traefik | ingress controller, ports 80/443 on every node |
| metrics-server | `kubectl top` and dashboard graphs |
| local-path-provisioner | default `StorageClass` for PersistentVolumes |
| Headlamp | dashboard, namespace `headlamp` |

`nfs-common` and `open-iscsi` are pre-installed on all nodes, so NFS volumes or Longhorn work without extra setup.

## Try it out

```sh
kubectl create deployment hello --image=nginx --replicas=2
kubectl expose deployment hello --port=80 --type=NodePort
kubectl get svc hello        # open http://<any-node-ip>:<nodeport>
```

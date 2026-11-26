#!/usr/bin/env bash
# Deploys TYPO3 to the libvirt cluster in infra/. Same idea as `ddev k8s-deploy`
# in bmack/typo3-k8s-starter, but against a real cluster:
#
# 1. builds the production image (docker/Dockerfile) and pushes it to the
#    cluster registry; the release uses its digest
# 2. creates the namespace and, once, the Secrets with random passwords
# 3. applies k8s/platform/ (MariaDB, Valkey, Garage S3, RWX volumes) and waits for it
# 4. empty database only: `typo3 setup` in a one-off pod (schema + admin user),
#    then the root page and sample pages (k8s/bootstrap.sql). The chart's
#    migrations hook can't do this: extension:setup needs be_users to exist.
# 5. runs `helm upgrade --install` with k8s/charts/typo3 and k8s/values/cluster.yaml
#    (the chart's migrations hook runs extension:setup first)
#
# Usage: k8s/deploy.sh [--no-build]
# Env:   KUBECONFIG (default infra/kubeconfig), REGISTRY (default: tofu output),
#        NAMESPACE (typo3), RELEASE (typo3)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAMESPACE="${NAMESPACE:-typo3}"
RELEASE="${RELEASE:-typo3}"
BUILDER=typo3-kate
export KUBECONFIG="${KUBECONFIG:-${ROOT}/infra/kubeconfig}"

step() { printf '\n\033[1m%s\033[0m\n' "$*"; }
fail() { echo "Error: $*" >&2; exit 1; }
k() { kubectl -n "${NAMESPACE}" "$@"; }
secret_value() { k get secret "$1" -o jsonpath="{.data.$2}" | base64 -d; }

for tool in docker kubectl helm jq openssl tofu; do
  command -v "${tool}" >/dev/null || fail "${tool} is not installed"
done
[ -f "${KUBECONFIG}" ] || fail "no kubeconfig at ${KUBECONFIG} (run tofu apply in infra/)"
REGISTRY="${REGISTRY:-$(tofu -chdir="${ROOT}/infra" output -raw registry)}"
MASTER_IP="${REGISTRY%%:*}"

# macOS may block kubectl/helm from the VM subnet (Local Network privacy) while
# ssh is allowed. Then talk to the API through an SSH tunnel on localhost;
# the k3s API certificate is valid for 127.0.0.1.
if ! kubectl get --raw /readyz >/dev/null 2>&1; then
  echo "API not reachable directly, using an SSH tunnel to ${MASTER_IP}"
  TUNNEL_DIR="$(mktemp -d)"
  trap 'ssh -S "${TUNNEL_DIR}/ctl" -O exit "ubuntu@${MASTER_IP}" 2>/dev/null; rm -rf "${TUNNEL_DIR}"' EXIT
  ssh -f -N -M -S "${TUNNEL_DIR}/ctl" -o ExitOnForwardFailure=yes \
    -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR \
    -L 127.0.0.1:16443:127.0.0.1:6443 "ubuntu@${MASTER_IP}"
  sed "s#https://${MASTER_IP}:6443#https://127.0.0.1:16443#" "${KUBECONFIG}" > "${TUNNEL_DIR}/kubeconfig"
  export KUBECONFIG="${TUNNEL_DIR}/kubeconfig"
  kubectl get --raw /readyz >/dev/null || fail "cluster not reachable, also not through SSH"
fi

# --- 1. image ---------------------------------------------------------------
if [ "${1:-}" = "--no-build" ]; then
  RELEASED="$(helm -n "${NAMESPACE}" get values "${RELEASE}" -o json 2>/dev/null | jq -r '.image.digest // empty')"
  [ -n "${RELEASED}" ] || fail "no deployed image yet, run without --no-build"
  DIGEST="${RELEASED}"
  step "Reusing image ${DIGEST}"
else
  ARCH="$(kubectl get nodes -o jsonpath='{.items[0].status.nodeInfo.architecture}')"
  TAG="$(git -C "${ROOT}" rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)"
  step "Building ${REGISTRY}/typo3:${TAG} for linux/${ARCH}"

  # A BuildKit builder that may push to the plain-HTTP cluster registry.
  if ! docker buildx inspect "${BUILDER}" >/dev/null 2>&1; then
    conf="$(mktemp)"
    printf '[registry."%s"]\n  http = true\n  insecure = true\n' "${REGISTRY}" > "${conf}"
    docker buildx create --name "${BUILDER}" --driver docker-container --buildkitd-config "${conf}" >/dev/null
    rm -f "${conf}"
  fi

  meta="$(mktemp)"
  docker buildx build --builder "${BUILDER}" --platform "linux/${ARCH}" \
    -f "${ROOT}/docker/Dockerfile" -t "${REGISTRY}/typo3:${TAG}" \
    --push --metadata-file "${meta}" "${ROOT}"
  DIGEST="$(jq -r '."containerimage.digest"' "${meta}")"
  rm -f "${meta}"
  [[ "${DIGEST}" == sha256:* ]] || fail "no image digest in the build output"
  echo "Pushed ${REGISTRY}/typo3@${DIGEST}"
fi

# --- 2. namespace + secrets ---------------------------------------------------
step "Namespace ${NAMESPACE}"
kubectl create namespace "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f - >/dev/null
kubectl label namespace "${NAMESPACE}" gateway-access=public --overwrite >/dev/null   # may attach to public-gateway

if ! k get secret typo3-env >/dev/null 2>&1; then
  echo "Creating Secrets with random passwords"
  db_password="$(openssl rand -hex 24)"
  valkey_password="$(openssl rand -hex 24)"
  k create secret generic typo3-env \
    --from-literal=TYPO3_ENCRYPTION_KEY="$(openssl rand -hex 48)" \
    --from-literal=TYPO3_DB_PASSWORD="${db_password}" \
    --from-literal=TYPO3_VALKEY_PASSWORD="${valkey_password}" >/dev/null
  k create secret generic typo3-mariadb \
    --from-literal=root-password="$(openssl rand -hex 24)" \
    --from-literal=typo3-password="${db_password}" >/dev/null
  k label secret typo3-mariadb k8s.mariadb.com/watch= >/dev/null
  k create secret generic typo3-valkey --from-literal=password="${valkey_password}" >/dev/null
  k create secret generic typo3-admin \
    --from-literal=username=admin \
    --from-literal=password="$(openssl rand -base64 18 | tr -d '/+=')A1." >/dev/null
fi

# Garage (S3): created separately, so clusters from before Garage get it too.
if ! k get secret typo3-garage >/dev/null 2>&1; then
  echo "Creating the Garage Secret"
  k create secret generic typo3-garage \
    --from-literal=rpc-secret="$(openssl rand -hex 32)" \
    --from-literal=admin-token="$(openssl rand -base64 32)" \
    --from-literal=access-key="GK$(openssl rand -hex 12)" \
    --from-literal=secret-key="$(openssl rand -hex 32)" >/dev/null
fi
if [ -z "$(k get secret typo3-env -o jsonpath='{.data.TYPO3_S3_KEY}')" ]; then
  echo "Adding the S3 credentials to typo3-env"
  k patch secret typo3-env --type merge -p "$(jq -n \
    --arg key "$(secret_value typo3-garage access-key)" \
    --arg secret "$(secret_value typo3-garage secret-key)" \
    '{stringData: {TYPO3_S3_KEY: $key, TYPO3_S3_SECRET: $secret}}')" >/dev/null
fi

# --- 3. platform ----------------------------------------------------------------
step "MariaDB, Valkey, volumes"
k apply -f "${ROOT}/k8s/platform/"
k wait mariadb/typo3-mariadb --for=condition=Ready --timeout=10m
k wait database/typo3 user/typo3 grant/typo3 --for=condition=Ready --timeout=5m
k rollout status statefulset/typo3-valkey --timeout=5m
k rollout status deployment/typo3-valkey-cache --timeout=5m
k rollout status statefulset/garage --timeout=5m
# The default bucket exists; serving it anonymously is a separate switch.
k exec garage-0 -- /garage bucket website --allow media >/dev/null
k wait pvc/typo3-fileadmin pvc/typo3-typo3temp-assets --for=jsonpath='{.status.phase}'=Bound --timeout=5m

# --- 4. first install ---------------------------------------------------------------
mariadb_root() {
  k exec -i typo3-mariadb-0 -c mariadb -- \
    mariadb -uroot -p"$(secret_value typo3-mariadb root-password)" typo3 "$@"
}

FIRST_INSTALL=false
if [ -z "$(mariadb_root -N -e "SHOW TABLES LIKE 'be_users'")" ]; then
  FIRST_INSTALL=true
  step "Empty database: installing TYPO3"
  # Same image as the release. `typo3 setup` writes settings.php, which is
  # root-owned in the image: give it a writable copy of config/system without
  # settings.php (thrown away with the pod; the image keeps its own).
  k delete pod typo3-install --ignore-not-found >/dev/null
  cat <<YAML | k apply -f - >/dev/null
apiVersion: v1
kind: Pod
metadata:
  name: typo3-install
spec:
  restartPolicy: Never
  automountServiceAccountToken: false
  securityContext:
    runAsNonRoot: true
    runAsUser: 1000
    runAsGroup: 1000
  volumes:
    - name: config-system
      emptyDir: {}
  initContainers:
    - name: copy-config
      image: ${REGISTRY}/typo3@${DIGEST}
      command: ["sh", "-c", "cp /app/config/system/* /config-system/ && rm /config-system/settings.php"]
      volumeMounts:
        - {name: config-system, mountPath: /config-system}
  containers:
    - name: setup
      image: ${REGISTRY}/typo3@${DIGEST}
      volumeMounts:
        - {name: config-system, mountPath: /app/config/system}
      command:
        - sh
        - -c
        - >-
          vendor/bin/typo3 setup --no-interaction --force --server-type=other
          --driver=mysqli --host=typo3-mariadb --port=3306 --dbname=typo3 --username=typo3
          --password="\$TYPO3_DB_PASSWORD"
          --admin-username="\$ADMIN_USERNAME" --admin-user-password="\$ADMIN_PASSWORD"
          --admin-email=admin@typo3-kate.local --project-name="K8s Starter"
      envFrom:
        - secretRef:
            name: typo3-env
      env:
        - name: TYPO3_DB_HOST
          value: typo3-mariadb
        - name: ADMIN_USERNAME
          valueFrom:
            secretKeyRef: {name: typo3-admin, key: username}
        - name: ADMIN_PASSWORD
          valueFrom:
            secretKeyRef: {name: typo3-admin, key: password}
YAML
  phase=""
  for _ in $(seq 1 120); do
    phase="$(k get pod typo3-install -o jsonpath='{.status.phase}')"
    [[ "${phase}" == Succeeded || "${phase}" == Failed ]] && break
    sleep 5
  done
  if [ "${phase}" != Succeeded ]; then
    k logs typo3-install --all-containers --tail=40 >&2 || true
    fail "typo3 setup ${phase:-did not finish} (pod typo3-install kept for inspection)"
  fi
  k logs typo3-install | grep -vE '"level":"(debug|info|notice)"' | tail -5
  k delete pod typo3-install >/dev/null
  mariadb_root < "${ROOT}/k8s/bootstrap.sql"
  echo "Root page, About and Contact created"
fi

# --- 5. helm ----------------------------------------------------------------------
step "Helm release ${RELEASE}"
helm upgrade --install "${RELEASE}" "${ROOT}/k8s/charts/typo3" \
  --namespace "${NAMESPACE}" \
  -f "${ROOT}/k8s/values/cluster.yaml" \
  --set image.repository="${REGISTRY}/typo3" \
  --set image.digest="${DIGEST}" \
  --wait --timeout 15m

if [ "${FIRST_INSTALL}" = true ]; then
  k exec deploy/"${RELEASE}"-backend -- vendor/bin/typo3 cache:flush   # caches in Valkey are shared
fi

# --- summary -------------------------------------------------------------------------
echo
k get pods -o wide
echo
echo "TYPO3:    https://${MASTER_IP}/   (every node IP works; trust infra/cluster-ca.crt to avoid warnings)"
echo "Backend:  https://${MASTER_IP}/typo3   user: $(secret_value typo3-admin username)"
echo "Password: kubectl -n ${NAMESPACE} get secret typo3-admin -o jsonpath='{.data.password}' | base64 -d"

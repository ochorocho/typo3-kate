#!/usr/bin/env bash
# Deploys one TYPO3 site (projects/<project>/) to the libvirt cluster in
# infra/. Same idea as `ddev k8s-deploy` in bmack/typo3-k8s-starter, but
# against a real cluster:
#
# 1. builds the project's production image (docker/Dockerfile) and pushes it
#    to the cluster registry; the release uses its digest
# 2. creates the project's namespace and, once, the Secrets with random passwords
# 3. applies k8s/platform/ (MariaDB, Valkey, Garage S3, RWX volumes) and waits for it
# 4. empty database only: `typo3 setup` in a one-off pod (schema + admin user),
#    then the root page and sample pages (k8s/bootstrap.sql). The chart's
#    migrations hook can't do this: extension:setup needs be_users to exist.
# 5. runs `helm upgrade --install` with k8s/charts/typo3: k8s/values/cluster.yaml,
#    the `helm:` section of ./kate.yaml (cluster-wide), the URLs and routes of
#    the project's domain, then the `helm:` section of the project's kate.yaml
#    (the chart's migrations hook runs extension:setup first)
#
# Usage: k8s/deploy.sh <project> [--no-build]   one site, e.g. kate
#        k8s/deploy.sh --all [--no-build]       every folder in projects/, one after the other
# Settings: projects/<project>/kate.yaml (domain, namespace, nodeIPs, helm:),
#           ./kate.yaml (git-ignored, cluster-wide, see kate.example.yaml)
# Env:   KUBECONFIG (default infra/kubeconfig), REGISTRY (default: tofu output),
#        RELEASE (typo3)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

step() { printf '\n\033[1m%s\033[0m\n' "$*"; }
fail() { echo "Error: $*" >&2; exit 1; }
usage() {
  echo "Usage: k8s/deploy.sh <project>|--all [--no-build]" >&2
  echo "Projects: $(find "${ROOT}/projects" -mindepth 1 -maxdepth 1 -type d ! -name '_*' -exec basename {} \; | sort | tr '\n' ' ')" >&2
  exit 1
}

PROJECT=""
ALL=false
BUILD=true
for arg in "$@"; do
  case "${arg}" in
    --no-build) BUILD=false ;;
    --all) ALL=true ;;
    -*) usage ;;
    *) [ -z "${PROJECT}" ] || usage; PROJECT="${arg}" ;;
  esac
done

if [ "${ALL}" = true ]; then
  [ -z "${PROJECT}" ] || usage
  flags=()
  [ "${BUILD}" = true ] || flags+=(--no-build)
  for dir in "${ROOT}"/projects/*/; do
    project="$(basename "${dir}")"
    [[ "${project}" == _* ]] && continue   # _example and other templates
    "$0" "${project}" ${flags[@]+"${flags[@]}"}
  done
  exit 0
fi

[ -n "${PROJECT}" ] || usage
[[ "${PROJECT}" =~ ^[a-z0-9]([-a-z0-9]{0,40}[a-z0-9])?$ ]] \
  || fail "project name '${PROJECT}': lowercase letters, digits and dashes only (it names the image and namespace)"
PROJECT_DIR="${ROOT}/projects/${PROJECT}"
[ -f "${PROJECT_DIR}/composer.json" ] || fail "no projects/${PROJECT}/composer.json"

# The project's settings (projects/<project>/kate.yaml, committed, no secrets).
# Without a domain the site answers on the node IPs; only one project can.
PROJECT_FILE="${PROJECT_DIR}/kate.yaml"
setting() { if [ -f "${PROJECT_FILE}" ]; then yq -r "$1" "${PROJECT_FILE}"; else echo "$2"; fi; }
NAMESPACE="$(setting ".namespace // \"${PROJECT}\"" "${PROJECT}")"
DOMAIN="$(setting '.domain // ""' "")"
ACME_ENVIRONMENT="$(setting '.acme.environment // "staging"' staging)"
NODE_IPS="$(setting '.nodeIPs // false' false)"
[ -n "${DOMAIN}" ] || NODE_IPS=true
[[ "${ACME_ENVIRONMENT}" == staging || "${ACME_ENVIRONMENT}" == production ]] \
  || fail "acme.environment in projects/${PROJECT}/kate.yaml must be staging or production"

RELEASE="${RELEASE:-typo3}"
BUILDER=typo3-kate

k() { kubectl -n "${NAMESPACE}" "$@"; }
secret_value() { k get secret "$1" -o jsonpath="{.data.$2}" | base64 -d; }

for tool in docker kubectl helm jq yq openssl tofu; do
  command -v "${tool}" >/dev/null || fail "${tool} is not installed"
done
# shellcheck source=k8s/cluster.sh
. "${ROOT}/k8s/cluster.sh"
IMAGE="${REGISTRY}/${PROJECT}"

# Cluster-wide settings (./kate.yaml, git-ignored): Helm values for every project.
KATE_FILE="${ROOT}/kate.yaml"
SITE_URL="https://${DOMAIN:-${MASTER_IP}}"

# --- 0. other projects ------------------------------------------------------
# Each namespace records its project (annotations typo3-kate/*). A namespace
# with the gateway-access=public label but no annotation is from before
# projects/ and counts as the node-IP project.
others="$(kubectl get namespaces -o json | jq -c --arg ns "${NAMESPACE}" --arg p "${PROJECT}" '
  [.items[] | {ns: .metadata.name, a: (.metadata.annotations // {}), l: (.metadata.labels // {})}
   | select(.a["typo3-kate/project"] != null or .l["gateway-access"] == "public")
   | select(.ns != $ns)
   | {ns, project: (.a["typo3-kate/project"] // "from before projects/"), domain: (.a["typo3-kate/domain"] // ""),
      nodeIPs: (.l["gateway-access"] == "public")}]')"
owner="$(kubectl get namespace "${NAMESPACE}" -o json 2>/dev/null | jq -r '.metadata.annotations["typo3-kate/project"] // ""' || true)"
[ -z "${owner}" ] || [ "${owner}" = "${PROJECT}" ] \
  || fail "namespace ${NAMESPACE} belongs to project ${owner}: set another namespace in projects/${PROJECT}/kate.yaml"
if [ -n "${DOMAIN}" ]; then
  taken="$(jq -r --arg d "${DOMAIN}" '.[] | select(.domain == $d) | "\(.project) (namespace \(.ns))"' <<<"${others}")"
  [ -z "${taken}" ] || fail "${DOMAIN} is already the domain of ${taken}"
fi
if [ "${NODE_IPS}" = true ]; then
  taken="$(jq -r '.[] | select(.nodeIPs) | "\(.project) (namespace \(.ns))"' <<<"${others}")"
  [ -z "${taken}" ] || fail "only one project can answer on the node IPs and ${taken} does: give projects/${PROJECT}/kate.yaml a domain"
fi

# --- 1. image ---------------------------------------------------------------
if [ "${BUILD}" = false ]; then
  RELEASED="$(helm -n "${NAMESPACE}" get values "${RELEASE}" -o json 2>/dev/null || echo '{}')"
  DIGEST="$(jq -r '.image.digest // empty' <<<"${RELEASED}")"
  [ -n "${DIGEST}" ] || fail "no deployed image yet, run without --no-build"
  IMAGE="$(jq -r --arg i "${IMAGE}" '.image.repository // $i' <<<"${RELEASED}")"
  step "Reusing image ${IMAGE}@${DIGEST}"
else
  ARCH="$(kubectl get nodes -o jsonpath='{.items[0].status.nodeInfo.architecture}')"
  TAG="$(git -C "${ROOT}" rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)"
  step "Building ${IMAGE}:${TAG} for linux/${ARCH}"

  # A BuildKit builder that may push to the plain-HTTP cluster registry.
  if ! docker buildx inspect "${BUILDER}" >/dev/null 2>&1; then
    conf="$(mktemp)"
    printf '[registry."%s"]\n  http = true\n  insecure = true\n' "${REGISTRY}" > "${conf}"
    docker buildx create --name "${BUILDER}" --driver docker-container --buildkitd-config "${conf}" >/dev/null
    rm -f "${conf}"
  fi

  meta="$(mktemp)"
  docker buildx build --builder "${BUILDER}" --platform "linux/${ARCH}" \
    -f "${ROOT}/docker/Dockerfile" --build-arg PROJECT="${PROJECT}" -t "${IMAGE}:${TAG}" \
    --push --metadata-file "${meta}" "${ROOT}"
  DIGEST="$(jq -r '."containerimage.digest"' "${meta}")"
  rm -f "${meta}"
  [[ "${DIGEST}" == sha256:* ]] || fail "no image digest in the build output"
  echo "Pushed ${IMAGE}@${DIGEST}"
fi

# --- 2. namespace + secrets ---------------------------------------------------
step "Namespace ${NAMESPACE} (project ${PROJECT})"
kubectl create namespace "${NAMESPACE}" --dry-run=client -o yaml | kubectl apply -f - >/dev/null
kubectl annotate namespace "${NAMESPACE}" --overwrite \
  typo3-kate/project="${PROJECT}" typo3-kate/domain="${DOMAIN}" >/dev/null
if [ "${NODE_IPS}" = true ]; then
  # may attach to public-gateway's https listener, which answers on the node IPs
  kubectl label namespace "${NAMESPACE}" gateway-access=public --overwrite >/dev/null
elif [ -n "$(kubectl get namespace "${NAMESPACE}" -o jsonpath='{.metadata.labels.gateway-access}')" ]; then
  kubectl label namespace "${NAMESPACE}" gateway-access- >/dev/null
fi

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
# Mercure JWT keys: always present, so mercure.enabled can be switched on any time.
if [ -z "$(k get secret typo3-env -o jsonpath='{.data.MERCURE_PUBLISHER_JWT_KEY}')" ]; then
  echo "Adding the Mercure JWT keys to typo3-env"
  k patch secret typo3-env --type merge -p "$(jq -n \
    --arg pub "$(openssl rand -hex 32)" \
    --arg sub "$(openssl rand -hex 32)" \
    '{stringData: {MERCURE_PUBLISHER_JWT_KEY: $pub, MERCURE_SUBSCRIBER_JWT_KEY: $sub}}')" >/dev/null
fi

# --- 2b. routing: Gateway, certificate, media route -----------------------------
step "Routing ${DOMAIN:-(node IPs only)}"
route_args=()
if [ -n "${DOMAIN}" ]; then
  issuer=letsencrypt-staging
  [ "${ACME_ENVIRONMENT}" = staging ] || issuer=letsencrypt
  route_args+=(--hostname "${DOMAIN}" --issuer "${issuer}")
fi
[ "${NODE_IPS}" = false ] || route_args+=(--node-ips)
"${ROOT}/k8s/project-manifests.sh" ${route_args[@]+"${route_args[@]}"} | k apply -f -
if [ -n "${DOMAIN}" ]; then
  # DNS-01 takes a few minutes the first time (TXT record, propagation)
  if ! k wait certificate/site --for=condition=Ready --timeout=10m; then
    k describe certificate site | tail -15 >&2 || true
    kubectl get challenges -n "${NAMESPACE}" -o wide >&2 || true
    fail "no certificate for ${DOMAIN}: is it in a Hetzner DNS zone that the token in ./kate.yaml may edit?"
  fi
  k wait gateway/site --for=condition=Programmed --timeout=2m
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
      image: ${IMAGE}@${DIGEST}
      command: ["sh", "-c", "cp /app/config/system/* /config-system/ && rm /config-system/settings.php"]
      volumeMounts:
        - {name: config-system, mountPath: /config-system}
  containers:
    - name: setup
      image: ${IMAGE}@${DIGEST}
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
          --admin-email=admin@typo3-kate.local --project-name="${PROJECT}"
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
# Values, last wins: repository defaults, cluster-wide overrides (./kate.yaml),
# the URLs and routes of the site, the project's overrides
site_args=("${SITE_URL}")
[ -z "${DOMAIN}" ] || site_args+=(--hostname "${DOMAIN}")
[ "${NODE_IPS}" = false ] || site_args+=(--node-ips)
"${ROOT}/k8s/site-values.sh" "${site_args[@]}" > "${WORK_DIR}/site.yaml"
helm_values() { if [ -f "$1" ]; then yq '.helm // {}' "$1"; else echo "{}"; fi; }
helm_values "${KATE_FILE}" > "${WORK_DIR}/cluster-kate.yaml"
helm_values "${PROJECT_FILE}" > "${WORK_DIR}/project-kate.yaml"
helm upgrade --install "${RELEASE}" "${ROOT}/k8s/charts/typo3" \
  --namespace "${NAMESPACE}" \
  -f "${ROOT}/k8s/values/cluster.yaml" \
  -f "${WORK_DIR}/cluster-kate.yaml" \
  -f "${WORK_DIR}/site.yaml" \
  -f "${WORK_DIR}/project-kate.yaml" \
  --set image.repository="${IMAGE}" \
  --set image.digest="${DIGEST}" \
  --wait --timeout 15m

if [ "${FIRST_INSTALL}" = true ]; then
  k exec deploy/"${RELEASE}"-backend -- vendor/bin/typo3 cache:flush   # caches in Valkey are shared
fi

# --- summary -------------------------------------------------------------------------
echo
k get pods -o wide
echo
if [ "${NODE_IPS}" = true ] && [ -n "${DOMAIN}" ]; then
  echo "TYPO3:    ${SITE_URL}/   (every node IP works too; trust infra/cluster-ca.crt for those)"
else
  echo "TYPO3:    ${SITE_URL}/"
fi
echo "Backend:  ${SITE_URL}/typo3   user: $(secret_value typo3-admin username)"
echo "Password: kubectl -n ${NAMESPACE} get secret typo3-admin -o jsonpath='{.data.password}' | base64 -d"

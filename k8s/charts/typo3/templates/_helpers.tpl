{{/* ------------------------------------------------------------------ */}}
{{/* Names and labels                                                    */}}
{{/* ------------------------------------------------------------------ */}}

{{- define "typo3.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "typo3.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "typo3.labels" -}}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" }}
app.kubernetes.io/name: {{ include "typo3.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{/* Selector labels for a component: call with (dict "ctx" . "component" "frontend") */}}
{{- define "typo3.selectorLabels" -}}
app.kubernetes.io/name: {{ include "typo3.name" .ctx }}
app.kubernetes.io/instance: {{ .ctx.Release.Name }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "typo3.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "typo3.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{- define "typo3.secretName" -}}
{{- if .Values.typo3.existingSecret -}}
{{- .Values.typo3.existingSecret -}}
{{- else -}}
{{- printf "%s-env" (include "typo3.fullname" .) -}}
{{- end -}}
{{- end -}}

{{/* Mercure: public hub URL (token audience) and trusted token issuer */}}
{{- define "typo3.mercurePublicUrl" -}}
{{- required "mercure.publicUrl is required, e.g. https://www.example.com/.well-known/mercure" .Values.mercure.publicUrl -}}
{{- end -}}

{{- define "typo3.mercureIssuer" -}}
{{- if .Values.mercure.trustedIssuer -}}
{{- .Values.mercure.trustedIssuer -}}
{{- else -}}
{{- $url := urlParse (include "typo3.mercurePublicUrl" .) -}}
{{- printf "%s://%s" $url.scheme $url.host -}}
{{- end -}}
{{- end -}}

{{- define "typo3.image" -}}
{{- if .Values.image.digest -}}
{{- printf "%s@%s" .Values.image.repository .Values.image.digest -}}
{{- else -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) -}}
{{- end -}}
{{- end -}}

{{/* ------------------------------------------------------------------ */}}
{{/* Environment shared by every TYPO3 container (web, jobs, workers)    */}}
{{/* ------------------------------------------------------------------ */}}
{{- define "typo3.env" -}}
- name: TYPO3_CONTEXT
  value: {{ .Values.typo3.context | quote }}
- name: TYPO3_TRUSTED_HOSTS_PATTERN
  value: {{ .Values.typo3.trustedHostsPattern | quote }}
- name: TYPO3_REVERSE_PROXY_IP
  value: {{ .Values.typo3.reverseProxyIP | quote }}
- name: TYPO3_LOG_LEVEL
  value: {{ .Values.typo3.logLevel | quote }}
- name: TYPO3_MAIL_FROM
  value: {{ .Values.typo3.mailFrom | quote }}
- name: TYPO3_DB_HOST
  value: {{ .Values.database.host | quote }}
- name: TYPO3_DB_PORT
  value: {{ .Values.database.port | quote }}
- name: TYPO3_DB_NAME
  value: {{ .Values.database.name | quote }}
- name: TYPO3_DB_USER
  value: {{ .Values.database.user | quote }}
- name: TYPO3_DB_TLS
  value: {{ ternary "1" "0" .Values.database.tls | quote }}
{{- if .Values.valkey.enabled }}
- name: TYPO3_VALKEY_HOST
  value: {{ .Values.valkey.host | quote }}
- name: TYPO3_VALKEY_PORT
  value: {{ .Values.valkey.port | quote }}
{{- with .Values.valkey.cacheHost }}
- name: TYPO3_VALKEY_CACHE_HOST
  value: {{ . | quote }}
{{- end }}
{{- with .Values.valkey.user }}
- name: TYPO3_VALKEY_USER
  value: {{ . | quote }}
{{- end }}
{{- end }}
{{- if .Values.mercure.enabled }}
- name: MERCURE_URL
  value: {{ printf "http://%s-mercure:%v%s" (include "typo3.fullname" .) .Values.service.port .Values.mercure.path | quote }}
- name: MERCURE_PUBLIC_URL
  value: {{ include "typo3.mercurePublicUrl" . | quote }}
- name: MERCURE_JWT_ISSUER
  value: {{ include "typo3.mercureIssuer" . | quote }}
{{- end }}
{{- if .Values.imgproxy.enabled }}
- name: TYPO3_IMGPROXY_URL
  value: {{ printf "http://%s-imgproxy:%v" (include "typo3.fullname" .) .Values.service.port | quote }}
- name: TYPO3_IMGPROXY_SOURCE_URL
  value: {{ required "imgproxy.sourceUrl is required, e.g. https://www.example.com" .Values.imgproxy.sourceUrl | quote }}
{{- end }}
{{- range $key, $value := .Values.typo3.extraEnv }}
- name: {{ $key }}
  value: {{ $value | quote }}
{{- end }}
{{- end -}}

{{- define "typo3.envFrom" -}}
- secretRef:
    name: {{ include "typo3.secretName" . }}
{{- end -}}

{{/* ------------------------------------------------------------------ */}}
{{/* Volumes: read-only root fs -> only these paths are writable         */}}
{{/* ------------------------------------------------------------------ */}}
{{- define "typo3.volumes" -}}
- name: var
  emptyDir:
    sizeLimit: {{ .Values.persistence.varSizeLimit }}
- name: typo3temp
  emptyDir:
    sizeLimit: {{ .Values.persistence.varSizeLimit }}
- name: tmp
  emptyDir:
    medium: Memory
    sizeLimit: 256Mi
- name: caddy-data
  emptyDir: {}
- name: caddy-config
  emptyDir: {}
{{- if .Values.persistence.fileadmin.enabled }}
- name: fileadmin
  persistentVolumeClaim:
    claimName: {{ required "persistence.fileadmin.existingClaim is required (create the RWX volume outside the chart, so 'helm uninstall' can never delete your files)" .Values.persistence.fileadmin.existingClaim }}
{{- end }}
{{- if .Values.persistence.typo3tempAssets.enabled }}
- name: typo3temp-assets
  persistentVolumeClaim:
    claimName: {{ required "persistence.typo3tempAssets.existingClaim is required" .Values.persistence.typo3tempAssets.existingClaim }}
{{- end }}
{{- end -}}

{{- define "typo3.volumeMounts" -}}
- name: var
  mountPath: /app/var
- name: typo3temp
  mountPath: /app/public/typo3temp
- name: tmp
  mountPath: /tmp
- name: caddy-data
  mountPath: /data
- name: caddy-config
  mountPath: /config
{{- if .Values.persistence.fileadmin.enabled }}
- name: fileadmin
  mountPath: /app/public/fileadmin
{{- end }}
{{- if .Values.persistence.typo3tempAssets.enabled }}
- name: typo3temp-assets
  mountPath: /app/public/typo3temp/assets
{{- end }}
{{- end -}}

{{/* ------------------------------------------------------------------ */}}
{{/* Pod spec for the web Deployments (frontend + backend)               */}}
{{/* call with (dict "ctx" . "component" "frontend" "cfg" .Values.frontend) */}}
{{/* ------------------------------------------------------------------ */}}
{{- define "typo3.webPodSpec" -}}
{{- $ctx := .ctx -}}
{{- with $ctx.Values.imagePullSecrets }}
imagePullSecrets:
  {{- toYaml . | nindent 2 }}
{{- end }}
serviceAccountName: {{ include "typo3.serviceAccountName" $ctx }}
automountServiceAccountToken: false
securityContext:
  {{- toYaml $ctx.Values.podSecurityContext | nindent 2 }}
terminationGracePeriodSeconds: {{ $ctx.Values.terminationGracePeriodSeconds }}
{{- if $ctx.Values.topologySpread.enabled }}
topologySpreadConstraints:
  - maxSkew: {{ $ctx.Values.topologySpread.maxSkew }}
    topologyKey: {{ $ctx.Values.topologySpread.topologyKey }}
    whenUnsatisfiable: {{ $ctx.Values.topologySpread.whenUnsatisfiable }}
    labelSelector:
      matchLabels:
        {{- include "typo3.selectorLabels" (dict "ctx" $ctx "component" .component) | nindent 8 }}
  - maxSkew: 1
    topologyKey: kubernetes.io/hostname
    whenUnsatisfiable: ScheduleAnyway
    labelSelector:
      matchLabels:
        {{- include "typo3.selectorLabels" (dict "ctx" $ctx "component" .component) | nindent 8 }}
{{- end }}
containers:
  - name: typo3
    image: {{ include "typo3.image" $ctx }}
    imagePullPolicy: {{ $ctx.Values.image.pullPolicy }}
    ports:
      - name: http
        containerPort: {{ $ctx.Values.containerPort }}
        protocol: TCP
    env:
      {{- include "typo3.env" $ctx | nindent 6 }}
      - name: TYPO3_ROLE
        value: {{ .component | quote }}
    envFrom:
      {{- include "typo3.envFrom" $ctx | nindent 6 }}
    startupProbe:
      httpGet:
        path: {{ $ctx.Values.probes.livenessPath }}
        port: http
      periodSeconds: {{ $ctx.Values.probes.startup.periodSeconds }}
      failureThreshold: {{ $ctx.Values.probes.startup.failureThreshold }}
    readinessProbe:
      httpGet:
        path: {{ $ctx.Values.probes.readinessPath }}
        port: http
      periodSeconds: {{ $ctx.Values.probes.readiness.periodSeconds }}
      timeoutSeconds: {{ $ctx.Values.probes.readiness.timeoutSeconds }}
      failureThreshold: {{ $ctx.Values.probes.readiness.failureThreshold }}
    livenessProbe:
      httpGet:
        path: {{ $ctx.Values.probes.livenessPath }}
        port: http
      periodSeconds: {{ $ctx.Values.probes.liveness.periodSeconds }}
      timeoutSeconds: {{ $ctx.Values.probes.liveness.timeoutSeconds }}
      failureThreshold: {{ $ctx.Values.probes.liveness.failureThreshold }}
    lifecycle:
      preStop:
        sleep:
          seconds: {{ $ctx.Values.lifecycle.preStopSleepSeconds }}
    resources:
      {{- toYaml .cfg.resources | nindent 6 }}
    securityContext:
      {{- toYaml $ctx.Values.securityContext | nindent 6 }}
    volumeMounts:
      {{- include "typo3.volumeMounts" $ctx | nindent 6 }}
volumes:
  {{- include "typo3.volumes" $ctx | nindent 2 }}
{{- with .cfg.nodeSelector }}
nodeSelector:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- with .cfg.tolerations }}
tolerations:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- with .cfg.affinity }}
affinity:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- end -}}

{{/* ------------------------------------------------------------------ */}}
{{/* Pod spec for one-off / background containers (jobs, workers)        */}}
{{/* call with (dict "ctx" . "command" (list ...) "resources" ...)       */}}
{{/* ------------------------------------------------------------------ */}}
{{- define "typo3.cliContainer" -}}
- name: {{ .name | default "typo3-cli" }}
  image: {{ include "typo3.image" .ctx }}
  imagePullPolicy: {{ .ctx.Values.image.pullPolicy }}
  workingDir: /app
  command:
    {{- toYaml .command | nindent 4 }}
  env:
    {{- include "typo3.env" .ctx | nindent 4 }}
  envFrom:
    {{- include "typo3.envFrom" .ctx | nindent 4 }}
  resources:
    {{- toYaml .resources | nindent 4 }}
  securityContext:
    {{- toYaml .ctx.Values.securityContext | nindent 4 }}
  volumeMounts:
    {{- include "typo3.volumeMounts" .ctx | nindent 4 }}
{{- end -}}

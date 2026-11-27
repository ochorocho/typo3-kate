# Several TYPO3 sites from one repository (projects/<name>/)

## Stage 1: Repository layout and image
**Goal**: projects/kate holds composer.json/lock, config/sites and kate.yaml; the image builds per project.
**Success Criteria**: `docker buildx build --build-arg PROJECT=kate` passes /_health/live and the settings.php check; vendor/bmack/k8s-health is a real directory.
**Tests**: CI image job with PROJECT=kate; composer validate per project.
**Status**: Complete

## Stage 2: deploy.sh <project>
**Goal**: deploy.sh reads projects/<p>/kate.yaml (namespace, domain, acme, nodeIPs); image per project; hostnames/parentRefs from site-values.sh; tunnel port range; guards.
**Success Criteria**: helm template for kate equals today's routes plus the new parentRef.
**Tests**: helm template per project, shellcheck.
**Status**: Complete

## Stage 3: Per-project Gateway, Certificate and media route
**Goal**: k8s/project-manifests.sh renders Gateway site, Certificate site, HTTPRoute media per namespace; deploy.sh applies and waits.
**Success Criteria**: gateway/site Programmed, certificate/site Ready; kate unchanged.
**Tests**: kubeconform on rendered manifests; live checks on the Odroid.
**Status**: Complete in code; live check on the Odroid pending

## Stage 4: Infra stops owning the domain
**Goal**: no https-domain listener or Certificate site in infra; ACME issuers only need the token.
**Success Criteria**: tofu validate; public-gateway lists http/https only; kate still served.
**Tests**: tofu fmt/validate, live checks.
**Status**: Not Started

## Stage 5: Second project, removal, docs
**Goal**: projects/_example, removal procedure, capacity note, docs updated.
**Success Criteria**: a second project deploys on its own domain without touching kate.
**Tests**: end-to-end on the Odroid (see plan).
**Status**: Not Started

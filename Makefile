# FluxFiles dev/test convenience targets.
PHP ?= 8.3

.PHONY: help test test-all test-php up down logs shell s3-minio

help:
	@echo "make test            # run the core suite in Docker (PHP=$(PHP))"
	@echo "make test-all        # run the suite on PHP 8.1, 8.2, 8.3, 8.4"
	@echo "make test-php PHP=8.4 # run the suite on a specific PHP version"
	@echo "make up / down       # start / stop the dev stack (app + MinIO)"
	@echo "make s3-minio        # run the live S3 test against a throwaway MinIO"
	@echo "make shell           # bash shell inside the test image"

test: test-php

test-php:
	docker build --build-arg PHP_VERSION=$(PHP) -f docker/Dockerfile -t fluxfiles-test:$(PHP) .
	docker run --rm fluxfiles-test:$(PHP)

test-all:
	@for v in 8.1 8.2 8.3 8.4; do \
		echo "=== PHP $$v ==="; \
		docker build -q --build-arg PHP_VERSION=$$v -f docker/Dockerfile -t fluxfiles-test:$$v . >/dev/null && \
		docker run --rm fluxfiles-test:$$v || exit 1; \
	done

up:
	docker compose up --build

down:
	docker compose down -v

logs:
	docker compose logs -f app

shell:
	docker build --build-arg PHP_VERSION=$(PHP) -f docker/Dockerfile -t fluxfiles-test:$(PHP) .
	docker run --rm -it fluxfiles-test:$(PHP) bash

s3-minio:
	docker rm -f ff-minio >/dev/null 2>&1 || true
	docker run -d --name ff-minio -p 9000:9000 -e MINIO_ROOT_USER=minioadmin -e MINIO_ROOT_PASSWORD=minioadmin123 minio/minio server /data
	@sleep 4
	FXTEST_S3_LABEL=MinIO FXTEST_S3_ENDPOINT=http://127.0.0.1:9000 FXTEST_S3_REGION=us-east-1 \
	FXTEST_S3_BUCKET=fluxfiles-test FXTEST_S3_KEY=minioadmin FXTEST_S3_SECRET=minioadmin123 \
	FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
	php packages/core/tests/e2e/test-s3-live.php; \
	docker rm -f ff-minio >/dev/null 2>&1

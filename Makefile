PHP_INI_ARGS ?=
LISTEN_HOST  ?= localhost
LISTEN_PORT  ?= 8080
MEMORY_LIMIT ?= 256M

.PHONY: server-start
server-start:
	php $(PHP_INI_ARG) \
		-d memory_limit=$(MEMORY_LIMIT) \
		-S $(LISTEN_HOST):$(LISTEN_PORT) \
		index.php

.PHONY: config
config:
	docker-compose config

.PHONY: build
build:
	COMPOSE_FILE=docker-compose.build.yml \
		docker-compose build $(args)

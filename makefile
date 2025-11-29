#!/usr/bin/make
.PHONY: help install update build dchv dchr dchn

PACKAGE_NAME = freshtracker
PROJECT = freshtracker
PATH_PROJECT = $(DESTDIR)/var/www/$(PROJECT)
INDEX_FILE ?= index.php

install: ##@system Install package. Don't run it manually!!!
	@echo Installing...
	install -d $(PATH_PROJECT)
	cp -r public/* $(PATH_PROJECT)/

	@# Get version info
	$(eval COMMIT_HASH := $(shell git rev-parse --short HEAD))
	$(eval VERSION := $(shell git log --oneline --format=%B -n 1 HEAD | head -n 1))
	$(eval DATE := $(shell git log --oneline --format="%at" -n 1 HEAD | xargs -I{} date -d @{} +%Y-%m-%d))
	@# Append version comment to index.html
	@echo "" >> $(PATH_PROJECT)/$(INDEX_FILE)
	@printf "\n<!-- Version $(VERSION), from $(DATE), commit hash '$(COMMIT_HASH)' -->\n" >> $(PATH_PROJECT)/$(INDEX_FILE)
	@printf "\n<!-- Version $(VERSION), from $(DATE), commit hash '$(COMMIT_HASH)' -->\n" >> $(PATH_PROJECT)/_version
	@sed -i 's/<meta name="version" content="VERSION_PLACEHOLDER">/<meta name="version" content="Version $(VERSION), build date: $(DATE), build hash: $(COMMIT_HASH)">/' $(PATH_PROJECT)/$(INDEX_FILE)

build:		##@build Build project
	@echo Building project
	@dpkg-buildpackage -rfakeroot --no-sign

update:		##@build Update project from GIT
	@echo Updating project from GIT
	git pull --no-rebase

help:
	@perl -e '$(HELP_ACTION)' $(MAKEFILE_LIST)

dchr:		##@development Publish release
	@dch --controlmaint --release --distribution unstable

dchv:		##@development Append release
	@export DEBEMAIL="karel.wintersky@yandex.ru" && \
	export DEBFULLNAME="Karel Wintersky" && \
	echo "$(YELLOW)------------------ Previous version header: ------------------$(GREEN)" && \
	head -n 3 debian/changelog && \
	echo "$(YELLOW)--------------------------------------------------------------$(RESET)" && \
	read -p "Next version: " VERSION && \
	dch --controlmaint -v $$VERSION

dchn:		##@development Initial create changelog file
	@export DEBEMAIL="karel.wintersky@yandex.ru" && \
	export DEBFULLNAME="Karel Wintersky" && \
	dch --create --package $(PACKAGE_NAME)

# ------------------------------------------------
# Add the following 'help' target to your makefile, add help text after each target name starting with '\#\#'
# A category can be added with @category
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
WHITE  := $(shell tput -Txterm setaf 7)
RESET  := $(shell tput -Txterm sgr0)
HELP_ACTION = \
	%help; while(<>) { push @{$$help{$$2 // 'options'}}, [$$1, $$3] if /^([a-zA-Z\-_]+)\s*:.*\#\#(?:@([a-zA-Z\-]+))?\s(.*)$$/ }; \
	print "usage: make [target]\n\n"; for (sort keys %help) { print "${WHITE}$$_:${RESET}\n"; \
	for (@{$$help{$$_}}) { $$sep = " " x (32 - length $$_->[0]); print "  ${YELLOW}$$_->[0]${RESET}$$sep${GREEN}$$_->[1]${RESET}\n"; }; \
	print "\n"; }

# -eof-
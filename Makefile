# Quick Booking: packaging and dev helpers
APP := quickbook
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' $(APP)/appinfo/info.xml)
DIST := dist/$(APP)-$(VERSION).tar.gz

.PHONY: package release dev lint clean

package: $(DIST)

$(DIST): $(shell find $(APP) -type f)
	mkdir -p dist
	tar --sort=name --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
		--exclude='.*' -czf $@ $(APP)
	cd dist && sha256sum $(notdir $@) > $(notdir $@).sha256
	@echo "Built $@"
	@cat $@.sha256

# Signed App Store release (see dev/release.sh)
release:
	./dev/release.sh

dev:
	./dev/setup.sh

lint:
	docker compose -f dev/compose.yaml exec -T nextcloud bash -c 'cd /var/www/html/custom_apps/$(APP) && find lib templates -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "^No syntax errors"' || true

clean:
	rm -rf dist

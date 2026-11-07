.PHONY: build clean

build:
	./scripts/build-zip.sh

clean:
	rm -rf dist

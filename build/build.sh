#!/usr/bin/env sh
# Build the installable archive module_markautomecanik-<version>.zip (Dolibarr / Dolistore format).
# The archive contains a single top-level directory "markautomecanik" and excludes the files
# marked "export-ignore" in .gitattributes. Run from a clean git checkout.
set -eu

cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/.*\$this->version = '\([^']*\)'.*/\1/p" core/modules/modMarkAutoMecanik.class.php)
if [ -z "$VERSION" ]; then
	echo "Unable to read the module version" >&2
	exit 1
fi
OUT="build/module_markautomecanik-$VERSION.zip"
rm -f "$OUT"
git archive --format=zip --prefix=markautomecanik/ -o "$OUT" HEAD
echo "Built $OUT"

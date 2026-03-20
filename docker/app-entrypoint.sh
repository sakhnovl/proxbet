#!/bin/sh
set -eu

mkdir -p /data

exec apache2-foreground

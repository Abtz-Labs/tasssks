# List available recipes (default)
default:
    @just --list

# Dev server
start:
    php -S localhost:8080 index.php

# Run test suite
test:
    php test.php

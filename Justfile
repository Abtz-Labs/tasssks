# List available recipes (default)
default:
    @just --list

# Dev server
start port="8080":
    php -S localhost:{{port}} index.php

# Run test suite
test:
    php test.php

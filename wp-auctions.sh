#!/bin/bash

semgrep scan --quiet --config=r/php.lang.security.injection.tainted-sql-string.tainted-sql-string plugins/wp-auctions

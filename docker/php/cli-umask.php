<?php

// Prepended to every PHP script in the container (auto_prepend_file). The services start with umask 027 from
// the entrypoint, but `docker exec php artisan ...` inherits Docker's 022 and would create world-readable files
// in /data. Only the CLI is changed: the web server already runs with 027, and umask is process-wide.
if (PHP_SAPI === 'cli') {
    umask(0027);
}

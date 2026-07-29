<?php
/**
 * public/ docroot entrypoint — delegates to the app root index.php.
 * Present so the site works if the host sets the PHP site docroot to public/.
 */
require __DIR__ . '/../index.php';

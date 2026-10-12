Google API PHP Client (vendor/)
===============================

This plugin ships a Composer-managed copy of google/apiclient (Calendar services
only) under vendor/.

Packages that Moodle core already provides (Guzzle, firebase/php-jwt, Monolog,
PSR HTTP/log interfaces, ralouphie/getallheaders, symfony/deprecation-contracts)
are declared in composer.json "replace" so they are NOT installed into vendor/.
The Composer autoloader is registered with prepend-autoloader=false so core
classloading wins for shared namespaces. The plugin sets a PSR NullLogger on
Google_Client so Monolog is never required at runtime.

Remaining third-party code under vendor/ (google/*, psr/cache) is listed in
thirdpartylibs.xml.

To rebuild vendor/ (requires network and Composer):

1. cd blocks/googlemeet_tutorials
2. rm -rf vendor composer.lock
3. composer install --no-dev
4. Confirm vendor/ contains only google/*, psr/cache, composer/, and autoload.php
   (no guzzlehttp/, firebase/, monolog/, or psr/http-* / psr/log)
5. Update thirdpartylibs.xml versions if package versions changed

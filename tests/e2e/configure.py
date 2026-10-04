"""Adds what the end-to-end tests need to the configuration that prepare.sh wrote: the school
of mock_idp.py as the provider of the single sign-on.

    bash prepare.sh && python3 tests/e2e/configure.py
"""

import os

import mock_idp

path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", ".config.local.php")
with open(path) as f:
    config = f.read()
empty = "'providers' => [],"
if empty not in config:
    raise SystemExit("the providers of the single sign-on are configured already, or the configuration is not the default one")
# written in place: the file is mounted into the container of the web server
with open(path, "w") as f:
    f.write(config.replace(empty, "'providers' => [" + mock_idp.PROVIDERS_PHP + "],"))
print("configured the single sign-on of the tests in", os.path.normpath(path))

#!/usr/bin/env python3
"""Stamp a release version into a BUILT WordPress plugin copy.

Called by build-wordpress.sh with the version taken from the wordpress-vX.Y.Z
tag. WordPress decides whether an update is available by comparing the header
in the installed plugin against the version an update channel advertises, so a
zip whose header lags its tag makes a site install the "update" and then be
offered the same one forever.

Rewrites three places that all have to agree:
  - the `Version:` plugin header WordPress reads,
  - the FLUXFILES_VERSION constant the code reads,
  - readme.txt's `Stable tag:`, which wordpress.org reads.

An optional third argument stamps FLUXFILES_CORE_VERSION, the release of the core
bundled into the build (from the latest core-vX.Y.Z tag, prefix stripped). An empty
plugin version skips the three edits above, so a core-only stamp is possible.

Operates on the build directory only; the committed sources are left alone and
stay in sync via the CI guard on the tag.
"""

import re
import sys


def main() -> int:
    if len(sys.argv) not in (3, 4):
        print('usage: stamp-wp-version.py <plugin-dir> <version> [core-version]', file=sys.stderr)
        return 2
    plugin_dir, version = sys.argv[1], sys.argv[2]
    core_version = sys.argv[3] if len(sys.argv) == 4 else ''

    main_file = plugin_dir + '/fluxfiles.php'
    src = open(main_file).read()
    if version:
        src, n_header = re.subn(
            r'^(\s*\*\s*Version:\s*).*$', r'\g<1>' + version, src, count=1, flags=re.M)
        src, n_const = re.subn(
            r"(define\('FLUXFILES_VERSION',\s*')[^']*('\);)",
            r'\g<1>' + version + r'\g<2>', src, count=1)
        if n_header != 1 or n_const != 1:
            print('ERROR: could not stamp fluxfiles.php (header=%d, const=%d)'
                  % (n_header, n_const), file=sys.stderr)
            return 1
    if core_version:
        src, n_core = re.subn(
            r"(define\('FLUXFILES_CORE_VERSION',\s*')[^']*('\);)",
            r'\g<1>' + core_version + r'\g<2>', src, count=1)
        if n_core != 1:
            print('ERROR: could not stamp FLUXFILES_CORE_VERSION', file=sys.stderr)
            return 1
    open(main_file, 'w').write(src)

    if version:
        readme = plugin_dir + '/readme.txt'
        txt = open(readme).read()
        txt, n_stable = re.subn(
            r'^(Stable tag:\s*).*$', r'\g<1>' + version, txt, count=1, flags=re.M)
        if n_stable != 1:
            print('ERROR: could not stamp readme.txt Stable tag', file=sys.stderr)
            return 1
        open(readme, 'w').write(txt)
        print('    stamped %s into fluxfiles.php (header + constant) and readme.txt' % version)
    if core_version:
        print('    stamped bundled core %s into fluxfiles.php' % core_version)
    return 0


if __name__ == '__main__':
    sys.exit(main())

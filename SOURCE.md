# Source and build notes

This package contains the runtime PHP source and readable built admin assets under `assets/build/`.

There is no frontend tracking script and no third-party browser dependency bundled in this release. The admin JavaScript is provided as a built WordPress admin asset and is versioned with the plugin release to avoid stale browser/admin caches after plugin upload updates.

For a public WordPress.org submission, publish the original development repository or include the original source files and build tooling needed to reproduce `assets/build/admin.js` and `assets/build/admin.css`.

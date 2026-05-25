# Release pipeline: tagged GitHub Releases with CI-built tarball, no deploy-time coupling

Versioned tarballs of this plugin are built by GitHub Actions on tag push (`vX.Y.Z`), attached to the matching GitHub Release alongside a `SHA256SUMS` file, and consumed by deployers as an opaque artifact. The plugin prescribes nothing about how it lands on a target — opcache invalidation, file ownership, post-install hooks, php-fpm reloads, and the osTicket-admin "Install/Enable" step are all the deployer's concern. Schema evolution beyond the initial install is deferred until a real migration is needed.

## Considered options

- **Continuous deploy from `main` (webhook-driven).** Rejected: single-tenant target, no fleet to keep in sync, and auto-deploying a plugin that runs inside a live ticketing system is asking for trouble.
- **Manual deploy of whatever `main` looks like (no tags).** Rejected: no clean rollback target; a tag is the cheapest pointable thing.
- **Deployer-side git-clone-the-tag with rsync excludes.** Rejected: forces every consumer to maintain their own copy of the `make build` exclude list, and risks shipping `.git/` and dev scaffolding when they get it wrong.
- **Manual `make build` + manual asset upload to Releases.** Rejected: footgun — forget to run the build and you ship a stale tarball under a fresh tag.
- **In-plugin migration runner (versioned SQL + applied-version tracking).** Deferred: no migration in flight, designing against imagined future schema is YAGNI; the gap is documented so a real change forces the decision.
- **Plugin orchestrating its own deploy (post-install hooks, opcache reset, etc.).** Rejected: we cannot know how the plugin will be deployed — Docker, bare metal, shared hosting, third-party CI/CD pipelines. The plugin's responsibility ends at the file boundary.

## Consequences

- Releasing requires one human moment: bump `plugin.php`'s `version`, commit, `git tag vX.Y.Z`, `git push --tags`. CI rejects the tag if the manifest version disagrees.
- Deployers consume a deterministic, verifiable artifact (`ai-response-suggester-X.Y.Z.tar.gz` + `SHA256SUMS`) at the stable URL `https://github.com/nikosch86/osTicket-AI-response-suggester/releases/download/vX.Y.Z/…`.
- The README must explicitly tell deployers what they are on the hook for: extracting to the right plugins path, ownership, opcache invalidation, and the one-time osTicket-admin install/enable click on a fresh install.
- The first schema change beyond `ai_crawler_content`'s initial shape will block on building an in-plugin migration mechanism.

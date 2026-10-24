# Legacy collections and Friends transfers

Collections use `collected_post` posts grouped by `post_collection` taxonomy
terms. The released 2.0.0 tag used a different, user-backed schema. Both Friends
Post Collection 1.x (`friend_post_cache`) and
released Post Collection 2.0.0 (`post_collection`) are upgrade sources.

## Upgrading an existing installation

1. Back up the database and plugin files. Deactivate the old plugin entry before
   activating this one; do not run two separate copies of the plugin together.
2. Activate/update the plugin, then open an individual site's admin as someone
   who can activate plugins. Normal updates are handled as well as activation.
3. The upgrade examines at most 100 posts per request. Further batches run through
   that site's WP-Cron, or the next admin request if cron is disabled. A notice
   reports an unfinished upgrade or a retryable error. Errors do not mark the
   schema as complete. Fix the reported storage problem and revisit the admin.
4. Review the collections in the standalone Post Collection app. Check article
   counts, tags, source authors, dates and public/private settings before resuming
   ordinary use. On multisite, do this for each site that used the old plugin;
   the admin request/cron callback never switches into other blogs.

Only posts owned by an account with the current site's `post_collection` role
are candidates. Ordinary Friends subscriptions and regular blog posts are left
alone. Drafts and trash are included. Existing `collected_post` articles are
associated only if they do not already have a collection. No URL is downloaded.

Each legacy account gets its own term, identified by
`_post_collection_legacy_user_id`. An unrelated same-slug collection is never
merged into it. Privacy, inactive/copy settings and frontend preferences are
copied once; subsequent retries do not overwrite settings already migrated.
Only explicit legacy true values (`true`, `1`, or the strings `true` and `1`,
ignoring case and surrounding whitespace) enable public access. Missing or
invalid publication preferences stay private, including partially migrated
settings. Existing WordPress account IDs and per-article `post_author` values are retained. A missing or invalid
default-user option (including an old stored `WP_Error`) is resolved from a valid
legacy default or an existing collection account before trying to create one.

Migration changes the post type in place. IDs, GUIDs, content, status, dates,
revisions, comments, metadata, tags, formats and Friends source relationships
remain intact. `_post_collection_legacy_type` records the original type. Direct
updates deliberately avoid publication hooks and outgoing notifications; post
caches and term counts are refreshed explicitly. A per-site cursor and lock
make batches resumable without repeatedly changing the same article.

The 1.x `post_tag` labels are also added to the app's `friend_tag` taxonomy,
reusing matching tag slugs and retaining the old tag relationships. No global
taxonomy is renamed. A standalone installation registers this tag taxonomy when
Friends is absent, so migrated labels remain usable there too.

`post_collection_schema_version = 1` means migration completed on that blog;
`post_collection_migration_state` is its temporary cursor. These are site options,
not network options. Legacy user options/accounts are retained for recovery.

## Daily use with Friends

- **Move** converts the existing Friends article into a private collected post
  and assigns the selected collection. The same ID, comments, revisions and
  provenance are retained. The article is not trashed or refetched.
- **Copy** creates a private collected post, copies stored metadata, terms and
  revisions, and leaves the source article unchanged. Comments remain with their
  original article. Temporary edit locks and transfer history are not copied.
- **Undo last move** is available immediately in the Friends menu and on the
  collected article's standalone detail page. It restores the recorded type,
  status, author and collection membership. Consecutive moves undo in reverse
  order. Undo is not offered for copies, which remain independent articles.
- Source subscriptions are retained, including Friends 4.4 taxonomy-backed
  subscriptions whose `post_author` is zero. Restoring into Friends requires
  Friends and the original account/subscription to remain available.
- An existing matching GUID in the destination returns the existing article.
  It does not overwrite that article, create another copy, or consume the source.
  Concurrent transfers are serialized per site; a busy request asks for a retry.
- AJAX endpoints check their nonce and the existing collection-management
  capability. Undo uses server-side history, not a supplied original author.
  Inactive destinations and unsupported source post types are rejected. Database
  and metadata failures return errors; a failed copy is removed and a failed move
  retains its source. Persisted collection membership and undo history are
  verified before reporting success. If compensation also fails, an explicit recovery error requires review
  before retrying. These operations do not invoke remote extraction services.

The original 2.0.0-era change-author AJAX endpoint is retained as a compatibility
adapter. Current menus use taxonomy terms. Saved articles are managed in the
standalone app; this does not restore collection feeds/views that upstream removed
from Friends in later `main` commits.

## Recovery and rollback

Take a fresh backup first. Before any new use, restoring the pre-upgrade database
and old plugin is the simplest complete rollback. After new activity, do **not**
blindly restore an old database or bulk-change every Friends post type. Review the
recorded legacy types and account/term mapping against the backup, and restore only
those migrated article IDs. Newly collected articles have no legacy type marker.
Per-post transfer history is separate from the upgrade audit marker. Legacy
accounts/options and source taxonomy relationships are intentionally not deleted.

## Reproducible tests

`composer install` then `composer test` runs the existing lightweight unit suite.

The real WordPress suite lives in `integration-tests/`, outside the stub suite.
It refuses to run without `PC_TEST_DISPOSABLE=1`; it **deletes test articles** and
must only use a throwaway installation/database. Its hardcoded database name,
table prefix and password are test fixtures, never production credentials.

Provide a disposable MySQL/MariaDB database named `post_collection_test`, root
password `disposable-test-only`, a freshly extracted WordPress directory, and:

```sh
export PC_TEST_DISPOSABLE=1
export PC_TEST_WP_ROOT=/tmp/wordpress
export PC_TEST_DB_HOST=127.0.0.1
# Optional: real Friends source (the suite otherwise models its taxonomy storage).
export PC_TEST_FRIENDS_PLUGIN=/tmp/friends/friends.php
php vendor/bin/phpunit -c integration-tests/phpunit.xml
php integration-tests/install-network.php
PC_TEST_MULTISITE=1 php vendor/bin/phpunit -c integration-tests/phpunit.xml
```

The suite covers both legacy types, multiple/resumed batches, trash, unrelated
authors, invalid defaults, slug conflicts, partial settings and database failures,
private access, moves/copies/undo, preserved source metadata/revisions/comments,
Friends subscription resolution, duplicate requests, busy transfers, rejected
AJAX requests and blog isolation. The network conversion command is for disposable
tests only, not a deployment instruction.

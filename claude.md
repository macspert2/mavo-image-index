# mavo-image-index — design notes

Built from `agent.md` (a spec written without knowledge of the sibling
plugins), adapted to how the mavo-* estate actually works. This file records
what was built and, more importantly, **where and why it differs from
agent.md**. Consumer documentation is in `README.md`.

---

## Files

| File | Purpose |
|---|---|
| `mavo-image-index.php` | Bootstrap: constants, requires, activation, `plugins_loaded` |
| `includes/api.php` | The public procedural API — the only contract consumers use |
| `includes/class-mavo-image-index-db.php` | `MII_DB`: the four tables, dbDelta, schema version |
| `includes/class-mavo-image-index-lang.php` | `MII_Lang`: languages, current language, bulk post languages |
| `includes/class-mavo-image-index-alt.php` | `MII_Alt`: adapter to the multilingual alt storage |
| `includes/class-mavo-image-index-concepts.php` | `MII_Concepts`: registry, extension, labels, dictionary version |
| `includes/class-mavo-image-index-matcher.php` | `MII_Matcher`: normalization, phrase compilation, matching |
| `includes/class-mavo-image-index-indexer.php` | `MII_Indexer`: items/semantics/concepts rows, dimensions |
| `includes/class-mavo-image-index-usage.php` | `MII_Usage`: extraction from posts, usage rows |
| `includes/class-mavo-image-index-geo.php` | `MII_Geo`: Geo Mashup + geotag-plus resolution, the ladder |
| `includes/class-mavo-image-index-images.php` | `MII_Images`: hydrates image objects in bulk |
| `includes/class-mavo-image-index-search.php` | `MII_Search`: two-stage search and scoring |
| `includes/class-mavo-image-index-status.php` | `MII_Status`: indexed / stale / failed / missing |
| `includes/class-mavo-image-index-rebuild.php` | `MII_Rebuild`: cursor-based batch steps (admin + CLI) |
| `includes/class-mavo-image-index-sync.php` | `MII_Sync`: incremental hooks, shutdown flush, cron overflow |
| `includes/class-mavo-image-index-cache.php` | `MII_Cache`: request + object cache under a generation number |
| `includes/class-mavo-image-index-shortcode.php` | `[mavo_image_more]` |
| `includes/class-mavo-image-index-popularity.php` | `MII_Popularity`: seasonal + recent views per article, for row order |
| `includes/class-mavo-image-index-place.php` | `MII_Place`: "X en images" galleries on place tag archives |
| `includes/class-mavo-image-index-results.php` | `MII_Results`: the results page, its URLs, rewrite rules, SEO filters |
| `includes/class-mavo-image-index-admin.php` | Tools → Image Index (admin only) |
| `includes/class-mavo-image-index-cli.php` | `wp mavo-image-index` (WP-CLI only) |
| `data/concepts.php` | The starter dictionary (39 concepts, fr/en/de) |
| `assets/admin.js`, `assets/admin.css` | The rebuild runner and admin styles |
| `assets/results.css` | Results-page spacing only; tiles come from the theme |
| `tests/` | `run.sh`; SQLite-backed harness |

---

## Divergences from agent.md

### 1. Hubs and geography follow the live model, not agent.md's

agent.md was written against `mavo-hub-manager`: `_mavo_hub_type`,
`_mavo_primary_geo_hub`, `_mavo_primary_theme_hub`. **Those keys no longer
exist.** `mavo-hubs` is live (confirmed 2026-10-03, no return): geographic hubs
were dropped, geography is the place tree of `mavo-geotag-plus`, and theme hubs
became an unordered `_mavo_hub` set with no primary.

So:

- Search takes **`place`** (a geotag-plus place = post_tag term ID,
  `include_place_descendants` default true) and **`hub`** (a mavo-hubs hub post
  ID, `include_hub_descendants` default false) instead of `geo_hub` /
  `theme_hub`.
- "Under a place" is a tag join: geotag-plus attaches every ancestor place to a
  post, and `mavo_geo_subtree_terms()` is added for posts whose ancestor tags
  are incomplete.
- Hub membership comes from `mavo_get_hub_children()` /
  `mavo_get_hub_descendants()`; per-usage memberships from `mavo_get_hubs()`
  (`'hubs' => true`). Never raw meta.
- The geo ladder's `geo_hub` and `country` rungs became
  `place_city / place_region / place_country / place_continent`.
- Places have no coordinates, so `lat`/`lng` come only from Geo Mashup
  (`post_exact`), read from its tables the way geotag-plus and geo-explorer do.

### 2. A fourth table, `mavo_image_items`

Orientation, width and height are search filters, so they need indexed
columns, not an unserialize of `_wp_attachment_metadata` per candidate. One row
per image attachment.

### 3. Geography is stored on usage rows

Each usage row carries a snapshot (`geo_precision`, `geo_confidence`,
`geo_lat/lng`, `geo_place`, `geo_country`, `geo_region`) resolved at index time. Reading an image's geography
is then one query, never a hierarchy walk per result. The snapshot is refreshed
by `set_object_terms` (post_tag, language), `geo_mashup_location_saved` and
`save_post` (priority 30, after geotag-plus tags at 20).

### 4. Accents are not folded

agent.md asks for matching that is "safe with French accents". Folding would
be unsafe: côte (coast) / côté (side), marche (walk) / marché (market). Text is
lowercased and NFC-normalized (so a decomposed é still matches), never folded.
The dictionary is written with accents.

### 5. Concepts are cross-language evidence

A concept is a fact about the picture. An image with only French alt is still
a beach for a German search. Each concept row keeps its language and source; the
API merges them (strongest confidence wins). `$lang` decides labels, alt text
returned, and — in search, through `same_language` — which posts' usages count.
`mavo_image_has_concept( $id, $c, $lang )` with a `$lang` restricts evidence to
that language.

### 6. Alt text comes from mavo-img-srcset's scheme, through an adapter

`_wp_attachment_image_alt` (fr) and `_mavo_alt_{lang}`, owned by
`Mavo_Alt_Admin` in mavo-img-srcset. `MII_Alt` asks `Mavo_Alt_Admin::meta_key()`
when callable and falls back to the same scheme, per
`sharing-between-plugins.md` pattern 3. Polylang media translation is not used
on this site.

### 7. Shortcode targets may be page IDs

A page ID maps through `pll_get_post()` to the link's language, so one entry
serves fr/en/de. An untranslated page yields no link in that language (falls
through to the generic `*` target) rather than a cross-language link.

### 8. A results page lives in this plugin (user's decision, 2026-10-03)

agent.md forbade an explorer page here. In practice `[mavo_image_more]` then had
nowhere to lead — site search finds articles, not images — so it printed
nothing. The user chose a server-rendered results page inside this plugin over
an overlay or a separate consumer plugin.

- One ordinary page (Tools → Image Index → Results page, set as the French
  page; Polylang translations serve en/de). Results are appended after its
  content, or placed with `[mavo_image_results]`;
  `[mavo_image_results concept="garden"]` makes a fixed-concept page.
- Each concept has its own path in each language's words, e.g.
  `/images/eaux-turquoise/`, `/en/pictures/turquoise-water/`, `…/2/` for page 2.
  Paths, not `?concept=`, because Swift Performance's handling of query strings
  is unknown; a path is cached per concept. `?concept=` is still read as a
  fallback before rules are flushed.
- Rules are built from each translation's real permalink path (`/en/` prefix
  included), so they do not depend on how Polylang rewrites rules. Paths are
  stored in an option; rules are flushed only when the setting or one of those
  pages changes. Deactivation deletes `rewrite_rules` so they disappear.
- Slugs are labels transliterated the German way (ü → ue), independent of the
  request locale; the concept slug itself (`turquoise-water`) is also accepted.
  Unknown slugs are 404s.
- The grid shows one tile per article (since 2026-10-05, see "Grouped
  tiles" below), links it to the article in the page's language, and
  paginates 24 articles per page. An article's
  featured image is used only when the article has no other photo of the
  concept, and such fallbacks come last (filter
  `mavo_image_results_featured_fallback` to drop them): on the live
  turquoise page, 11 of the first 24 tiles had been featured images, which
  look exactly like the site's ordinary post tiles. Related concepts
  of the same group and a link back to the index follow.
- Look: the theme's own components from `mv-tiles.css` (loaded on every page),
  in the markup of its `template-parts/mv-shared/card-post.php`: photos are
  `mv-tile mv-tile--media` in `mv-tile-grid mv-grid--3` (country, region as eyebrow,
  article title as the stretched link, the article's excerpt as the
  description — since 2026-10-04, by mavo-for-you's card rule: excerpt, a
  page's Yoast description first, 130 characters — and the photo's alt text
  on the img). **Never `get_the_excerpt()`**: for an article with no written
  excerpt it runs `the_content`, which re-entered `append_to_page()` and
  appended the grid again, recursively — `/images/fleurs/` exhausted 256 MB
  (2026-10-05). The written excerpt, else the opening 40 words with shortcodes
  and markup stripped; `append_to_page()` also refuses to re-enter while
  results render., the index is `mv-tile--text mv-tile--compact` with
  `mv-tile__count`, related/back links are `mv-badge`. `assets/results.css`
  holds only spacing. If the theme renames these classes, this follows.
- The bare page (no concept) is a browse page (user's decision,
  2026-10-03): the concepts listed in Tools → Image Index → *Browse rows*, in
  that order, each as a row of up to 12 articles — one tile per article, with
  its photo matching the row rather than its featured image — then the home
  page hero's three buttons, and nothing else: no "Pour vous" block (hidden
  through mavo-for-you's `mavo_for_you_show_block`) and no concept list (both
  removed at the user's request, 2026-10-03). The buttons belong to the theme
  (`inc/mv-hero-cta.php`, `mv_hero_cta_row()`), asked for through the
  `mavo_hero_cta_row` filter; without an answer none are printed. With no rows
  configured the page falls back to the concept list. A row with fewer than 4
  articles in a language is skipped in that language
  (`mavo_image_results_row_min`). Server-rendered and identical for everyone,
  so cached and indexable, unlike /pour-vous/.
- Row order (user's request, 2026-10-03): every article in the language using
  a photo of the concept (one grouped query, not the first page of an image
  search), ordered by `MII_Popularity` — views in the same month last year
  from `wp_rpp_monthly_snapshots` (recent-post-popularity), **summed over the
  Polylang translation group**, because EN/DE tracking is under a year old and
  their own last-year rows are empty; then the rolling 90-day `views` meta;
  then the newest photo. Featured-only articles still come last. The stats
  table is checked for, not assumed. No photo twice in a row: an article
  whose only photo another article already shows is skipped.
- The concept grid pages (`/images/eaux-turquoise/`) use the same ranking:
  `ranked_articles()` serves rows, grids and place-gallery faces.
- **Grouped tiles** (user's decision after a mock, 2026-10-05): one tile per
  article carrying every photo of it that shows the concept
  (`grid_articles()`). From two photos the theme's 16:10 `.mv-tile__media`
  becomes a mosaic — main photo large, one/two small, "+N" on the last, an
  "N photos" pill; small photos are `alt=""`, the main one carries the alt
  text. From `WIDE_MIN` (4) photos a tile spans two columns (2 × 2 smalls),
  at most one in `WIDE_EVERY` (6) tiles, decided over the whole list so pages
  agree; `grid-auto-flow: dense` keeps rows full; on phones a wide tile is
  one column again. A photo used by two articles appears once, under the
  first; an article left with none is dropped. Only the page's articles are
  hydrated (main photo via `MII_Images`, the others' meta primed).
- **Article order** (same date): popularity and relevance fused by rank
  (reciprocal rank fusion, k = `FUSION_K` 10). Popularity rank as before
  (`MII_Popularity`); relevance = log(1 + matching photos) +
  `SHARE_WEIGHT` (2) × matching ÷ all photos of the article — an article where
  8 of 15 photos show the concept is about it, one such photo in a 60-photo
  city guide is not. Featured-only fallbacks still last. Each article carries
  `n`, `share`, `pop_rank`, `rel_rank`, `score`; filter
  `mavo_image_results_ranked_articles`.
- Tile eyebrow (2026-10-04): **"Country, Region"** of the article's place, in
  its language — the city a post is tagged with was too specific. Either alone
  when only one is known; nothing without either. Usage rows store
  `geo_country` / `geo_region` term IDs beside `geo_place` (schema 2), filled
  from the same geotag-plus chain at index time. Filter
  `mavo_image_tile_eyebrow` is the seam for the planned mavo-location plugin
  (London / GB-South / Denmark by level).
- Rows are the theme's **`.mv-shelf`** component (CSS in `mv-tiles.css`,
  arrows in the theme's `js/mv-shelf.js`, registered as `mv-shelf` and
  enqueued here only when rows render). It was moved out of mavo-for-you's
  /pour-vous/ page so both pages draw the same row; For You now builds
  `.mv-shelf` markup and calls `window.mvShelf.enhance()`. Without the theme
  script the strips still scroll, just without arrows.
- SEO: title and Yoast title/description name the concept; canonical, og:url
  and Polylang's hreflang/switcher (`pll_translation_url`) point at the concept
  URL in each language, never the bare page.
- `[mavo_image_more]` falls back to this page after any explicit or mapped
  target, so choosing the page is normally the only setting needed.

### 8b. `[mavo_image_more]` looks like `[mavo_link]` (2026-10-04)

It emits mavo-custom-shortcodes' `[mavo_link]` markup verbatim — a `<div
class="mavo-related-link">` with a `__label` span and a `__anchor` link — plus
its own `mavo-image-more*` classes, and ships no CSS of its own: that
plugin's stylesheet is loaded on every singular page, where the shortcode
lives. Label per language (`En images :` / `In pictures:` / `In Bildern:`),
`label` attribute and `mavo_image_more_label` filter. If mavo-custom-shortcodes
renames those classes, this follows; without it the line is unstyled.

### 8c. Place galleries and topic × place pages (2026-10-04)

`MII_Place` (`class-mavo-image-index-place.php`): "Madère en images" — up to
four of the theme's overlay tiles, one per *distinctive* topic of a place,
each linking to `/images/{topic}/{place}/`.

- Inserted on tag archives through GeneratePress's `generate_before_loop`
  (`archive.php` fires it with `'archive'`), because tag archives run no
  shortcodes; not on page 2+. `[mavo_image_place place="madere"]` elsewhere
  (landing pages). Filter `mavo_image_place_auto` to switch insertion off.
- Topics: lift = (concept share of the place's photos) / (its share of the
  language's photos); needs ≥ 1.3 and ≥ 4 photos; ranked log(lift)·√photos;
  ≤ 2 per dictionary group; family/children/family_from_behind excluded.
  Filter `mavo_image_place_topics`.
- Photos counted are those in articles whose **own** place (usage
  `geo_place`) lies in the place's subtree — not every article tagged with it,
  which would let a round-up tagged Madère and Crete add Cretan beaches.
- No gallery when: the place holds > 25 % of the language's photos (checked
  with one COUNT before the topic query — the user asked about switching off
  big places dynamically; this is that, by data rather than level; filter
  `mavo_image_place_max_share`), fewer than 3 topics qualify, or no results
  page exists in that language.
- Topic × place pages: a second rewrite rule per results page
  (`…/{topic}/{place-slug}/[n/]`; a place slug never starts with a digit, so
  it cannot be a page number). `RULES_VERSION` forces one flush when the rule
  set changes. The same grouped tiles; the gallery tile's photo count is
  exactly that page's photo count. **noindex, follow**
  (core, Yoast, Rank Math) — topics × places are many and often thin; the
  canonical is the page itself; hreflang uses `pll_get_term()` for the place,
  falling back to the topic page. Links out: the topic everywhere, the place's
  tag archive.

### 8d. Private concepts: family and children (user's decision, 2026-10-05)

The blog is personal and public, but its photos of the children should not be
listable in one place. `'private' => true` in `data/concepts.php` makes a
concept indexed and searchable from PHP (`mavo_image_search()`, exclusion
filters) and absent from every public surface: no topic or topic × place
page (a plain 404, like a typo), no URL from `MII_Results::url()`, no "Voir
aussi" pill, no entry in the concept list or browse rows (stored rows are
filtered too), never a place-gallery topic, no `[mavo_image_more]` link even
with an explicit `url`. One check: `MII_Concepts::is_public()` (filter
`mavo_image_concept_is_public`). Any new public surface must use it.

`family_from_behind` (agent.md's example) was removed outright: the alt-text
rules write "family from behind" for photos that often show a single child,
so the concept was mostly untrue. "Famille de dos" now yields family;
"Enfant de dos" children; "de dos" alone nothing.

### 9. Smaller things

- `hero` is a valid role with no detector; whatever renders heroes can add rows
  via `mavo_image_usage_extract`. `gallery` is detected from `[gallery ids]`.
- `mavo-picture-tag` is not deployed; its markup is not looked for.
  `mavo-img-tag` carries no information (it is added at render time by
  mavo-img-srcset, never stored).
- Visitor-facing text (shortcode) uses per-language arrays, the project
  convention; admin text uses `__()` like mavo-img-srcset's admin.
- Photo tiles carry `data-post-id` (2026-10-04): mavo-search counts clicks
  on its search page's photo row by it.
- `mavo_image_concept_row( $concept, $lang, $args )` (2026-10-04) prints one
  browse row anywhere — the search page shows one when the query is exactly a
  concept. Same tiles, order and "see all" link as the browse page.
- `mavo_image_match_concepts( $text, $lang )` (2026-10-04) runs the matcher on any
  text, for mavo-search's query parsing, so that plugin never names `MII_Matcher`.
- `mavo_image_concepts( $lang )`, `mavo_image_concept_counts( $lang )` and
  `mavo_image_results_url( $concept, $lang )` were added for consumers.
- Search defaults `same_language => true`, which also excludes images used
  nowhere. `same_language => false` includes them.

---

## Matching

`data/concepts.php` documents the phrase syntax: `plage(s)` optional suffixes,
`*strand` compound heads, `türkis*` stems (four-letter minimum), weighted
`[ 'phrase', 0.8 ]`, per-concept `except` vetoes (an overlapping except phrase
kills a match: *le tour du lac* is not a tower, *Halbinsel* not an island), and
`implies` (one concept adds another at 0.9×). No dictionary text ever reaches
`preg_*` as a pattern.

The dictionary version is `md5( MII_Matcher::VERSION . serialize( definitions ) )`.
Any change — data file, registered concept, filter — makes every semantics row
stale. Bump `MII_Matcher::VERSION` when the matching *rules* change.

Tuned against the corpus 2026-10-04: `image-alt-registry.csv` (7,977 images,
fr/en/de; gitignored, kept in the plugin folder, growing). Coverage — alts with
at least one concept — went from 61/65/56 % to 75/78/68 % (fr/en/de) by adding
20 concepts from the most frequent unmatched words (bay, valley, hills, park,
museum, bridge, market, palace, ruins, lighthouse, canal, square, flowers,
palm_trees, street_art, sculpture, night, snow, autumn, christmas, campervan,
food), loosening French "tour" (42 → 137 photos), and joining hyphenated
proper names into one token (`MII_Matcher::join_names()`, matcher version 2):
"Camaret-sur-Mer" had been the sea, "Mont-Saint-Michel" would be a mountain.

## Indexing lifecycle

- **Full**: `MII_Rebuild::step( 'images' | 'usages', $cursor )` — cursor = last
  ID, so an interrupted rebuild resumes by starting again. The final step
  deletes rows for attachments/posts that no longer qualify.
- **Stale**: `MII_Status::stale_ids()` — hash or dictionary mismatch, failed,
  never indexed, orphaned rows, missing items row. Hashes are compared in SQL
  (`MD5(meta_value)`).
- **Incremental**: `MII_Sync` collects IDs from hooks and processes them once at
  shutdown, at most 20 attachments + 20 posts inline; the rest go to a WP-Cron
  queue (`mavo_image_index_queue`). Attachment and post deletions are immediate.

## Caching

`MII_Cache`: per-request array + object cache, keys prefixed with a generation
number (`mavo_image_index_cache_gen`). Any index write bumps it. No transients.
The only front-end output (the shortcode) is identical for every visitor, so
Swift Performance page caching is safe.

## Performance (SQLite benchmark, 7,000 images, 1,500 posts)

Single `mavo_image_get`: 4 queries. 20-result searches (AND, filtered, random,
text): 5–7 queries, independent of result count, 3–11 ms. Random order is a
seeded affine permutation modulo a prime — deterministic per seed (default:
changes daily), never `ORDER BY RAND()`.

## Tests

`tests/run.sh` runs each `test-*.php` in its own process against an in-memory
SQLite `$wpdb` (`tests/harness.php`), so the real SQL runs. The plugin's SQL is
therefore kept to what MySQL and SQLite share. Integration stubs (Polylang,
geotag-plus, mavo-hubs) live in `stubs-integrations.php` and are included only
by the tests that want them; `test-no-integrations.php` proves degradation.

Not covered by tests: dbDelta itself, the admin screen, WP-CLI output, real
rewrite matching and Polylang/Yoast behaviour on the results page, and
MySQL-specific behaviour (collation-insensitive LIKE in text search — MySQL's
`utf8mb4_unicode_ci` additionally ignores accents there, SQLite does not).

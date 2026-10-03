# agent.md — `mavo-image-index`

## Objective

Build a new WordPress plugin named **`mavo-image-index`** for Maman Voyage.

The plugin is a **shared image-intelligence/indexing layer**. Its job is to turn existing image metadata—especially high-quality multilingual alt texts—into a normalized semantic index that other Mavo plugins can query.

Primary responsibilities:

1. index image alt text into language-neutral canonical concepts;
2. expose a stable PHP API for other plugins;
3. index where images are used and in what role;
4. expose approximate geographic context inherited from posts/hubs when image-level geo is unavailable;
5. support Polylang languages `fr`, `en`, `de`;
6. optionally provide a **very small set of thin server-rendered shortcodes** for semantic cross-links.

Do **not** turn this plugin into a gallery, map, For You system, recommendation renderer, or visual explorer. Those should consume this API.

---

## Site context

Maman Voyage is a multilingual WordPress travel blog.

Relevant environment:

- WordPress
- Polylang active
- languages: `fr`, `en`, `de`
- French is the default/fallback language
- site-specific plugins use prefix `mavo-`
- approximately **7,000+ images** already have good alt texts, with coverage growing
- many posts are geo-tagged, but images usually do not carry their own precise location
- custom hub system exists

Existing hub metadata:

```text
_mavo_hub_type = geo | theme
_mavo_primary_geo_hub   = <post ID>
_mavo_primary_theme_hub = <post ID>
```

Hub hierarchy is inferred dynamically from those primary relationships.

The plugin must not edit unrelated plugins, the parent theme, image files, post content, Polylang relationships, or existing hub metadata.

---

# Architectural principles

## 1. Data/API first

The plugin should provide:

- semantic concept indexing;
- multilingual alt access;
- usage indexing;
- approximate geo/context resolution;
- image metadata normalization;
- semantic search;
- best-match selection;
- admin/CLI tools;
- extensibility hooks;
- one minimal semantic-link shortcode.

Do **not** build in V1:

- galleries;
- maps;
- sliders;
- For You UI;
- related-post card UI;
- hub UI;
- JS-heavy explorers;
- recommendation pages.

## 2. Public API must hide storage details

Other plugins must not know:

- which custom tables exist;
- how alt text is stored;
- how concepts are matched;
- how usage is detected;
- how geo is inferred;
- whether future concepts come from alt, caption, manual tags, or image analysis.

Use stable public helper functions.

Suggested public API:

```php
mavo_image_get( int $attachment_id, array $args = [] ): array;

mavo_image_get_alt(
    int $attachment_id,
    ?string $lang = null
): ?string;

mavo_image_get_concepts(
    int $attachment_id,
    ?string $lang = null
): array;

mavo_image_has_concept(
    int $attachment_id,
    string $concept,
    ?string $lang = null
): bool;

mavo_image_get_usages(
    int $attachment_id,
    array $args = []
): array;

mavo_image_get_geo_context(
    int $attachment_id,
    array $args = []
): array;

mavo_image_search( array $args = [] ): array;

mavo_image_best_match( array $args = [] ): ?array;

mavo_register_image_concept(
    string $slug,
    array $definition
): void;
```

Internal code may use a namespace or `mavo_image_index_` prefix, while public wrappers remain concise `mavo_image_*` functions.

---

# Core image object

Conceptually, `mavo_image_get()` should expose something like:

```php
[
    'attachment_id' => 54491,
    'url'            => 'https://...',
    'mime_type'      => 'image/jpeg',

    'alt' => [
        'fr' => 'Plage de Milos aux eaux turquoise à Lefkada',
        'en' => 'Milos Beach with turquoise water on Lefkada',
        'de' => 'Milos Beach mit türkisfarbenem Wasser auf Lefkada',
    ],

    'concepts' => [
        [
            'slug'       => 'beach',
            'source'     => 'alt',
            'confidence' => 1.0,
        ],
        [
            'slug'       => 'turquoise_water',
            'source'     => 'alt',
            'confidence' => 1.0,
        ],
    ],

    'dimensions' => [
        'width'        => 1200,
        'height'       => 800,
        'aspect_ratio' => 1.5,
        'orientation'  => 'landscape',
    ],

    'usages' => [
        [
            'post_id' => 12345,
            'lang'    => 'fr',
            'role'    => 'content',
        ],
    ],

    'geo' => [
        // preferred/summary geo context
    ],
]
```

Exact internal representation may differ; public semantics should remain equivalent.

---

# Canonical semantic concepts

Concepts are language-neutral slugs.

Starter examples:

```text
beach
sea
coast
cliff
mountain
lake
island
river
forest
waterfall
harbour
garden
old_town
village
street
coastal_path
viewpoint
sunset
panoramic_view
turquoise_water
blue_sky
colourful_houses
white_houses
stone_houses
family
children
family_from_behind
cycling
hiking
walking
boating
sailing
swimming
castle
church
tower
landmark
```

Start with approximately **30–50 useful concepts**, not every common noun.

A concept should exist because it can support a useful visitor-facing feature.

---

# Multilingual concept dictionary

Each concept should support:

- labels in `fr`, `en`, `de`;
- synonyms/phrases per language;
- optional match type/rules.

Example:

```php
[
    'beach' => [
        'labels' => [
            'fr' => 'Plage',
            'en' => 'Beach',
            'de' => 'Strand',
        ],
        'synonyms' => [
            'fr' => ['plage', 'plage de sable'],
            'en' => ['beach', 'sandy beach'],
            'de' => ['Strand', 'Sandstrand'],
        ],
    ],

    'turquoise_water' => [
        'labels' => [
            'fr' => 'Eaux turquoise',
            'en' => 'Turquoise water',
            'de' => 'Türkisfarbenes Wasser',
        ],
        'synonyms' => [
            'fr' => ['eau turquoise', 'eaux turquoise'],
            'en' => ['turquoise water', 'turquoise waters'],
            'de' => ['türkisfarbenes Wasser', 'türkises Wasser'],
        ],
    ],
]
```

---

# Matching requirements

Concept detection must be:

- Unicode-aware;
- case-insensitive;
- phrase-aware;
- safe with French accents;
- safe with German umlauts;
- boundary-aware;
- tolerant of explicit singular/plural variants;
- capable of overlapping matches.

Do **not** use naive substring matching for all concepts.

Bad:

```php
strpos( $alt, $keyword ) !== false
```

Use token/phrase boundaries where appropriate so short words do not match inside unrelated longer words.

Longer phrases may match independently of their component terms.

Example:

```text
plage de sable aux eaux turquoise
```

may legitimately map to:

```text
beach
turquoise_water
sea
```

if the dictionary supports those matches.

---

# Extensibility

Other plugins must be able to register concepts without modifying this plugin.

Provide:

```php
mavo_register_image_concept( ... );
```

and/or:

```php
do_action( 'mavo_image_register_concepts' );
```

This should allow concepts such as:

```text
harry_potter
street_art
jane_austen
```

Also expose filters for:

- concept definitions;
- labels;
- matcher output;
- relevance scoring;
- geo resolution;
- shortcode target URLs.

---

# Polylang integration

Polylang is active.

V1 supports:

```text
fr
en
de
```

but avoid architecture that prevents future languages.

When `$lang` is omitted:

1. use current Polylang language if available;
2. otherwise fall back to `fr`.

Use Polylang public APIs when available.

Gracefully degrade if Polylang is disabled or unavailable; do not fatal.

Canonical concepts remain language-neutral.

Language affects:

- alt text used for matching;
- labels returned;
- post/usages included by default;
- semantic-link target language.

By default, post-context searches should remain in the current language.

---

# Multilingual alt-text source

Do **not** assume `_wp_attachment_image_alt` alone can represent the project's three languages.

Before implementation, inspect the existing multilingual image-alt registry/storage mechanism and reuse it where practical.

Create a clean adapter layer so the rest of the plugin can simply call:

```php
mavo_image_get_alt( $attachment_id, 'fr' );
mavo_image_get_alt( $attachment_id, 'en' );
mavo_image_get_alt( $attachment_id, 'de' );
```

If an image has alt text in only one language, index what exists and continue gracefully.

---

# Image usage model

An attachment may appear in multiple posts.

Do **not** use attachment `post_parent` as the sole source of truth.

Index real usage.

Roles to support:

```text
featured
content
gallery
hero
```

V1 must reliably detect at least:

```text
featured
content
```

Detect featured images through `_thumbnail_id`.

Detect content images using, where practical:

- `wp-image-123` classes;
- attachment IDs embedded in post content;
- attachment URLs as a safe fallback.

Example classic-editor markup:

```html
class="size-full wp-image-54491 aligncenter mavo-img-tag"
```

Avoid scanning all post content on frontend requests. Usage extraction should happen during rebuilds and incremental post updates.

---

# Usage table

Use a dedicated usage table in V1.

Suggested name:

```text
{$wpdb->prefix}mavo_image_usage
```

Suggested fields:

```text
id
attachment_id
post_id
lang
role
source
created_at
updated_at
```

Useful indexes:

```text
PRIMARY KEY (id)
UNIQUE/compound key for attachment_id + post_id + role
INDEX attachment_id
INDEX post_id
INDEX lang
INDEX role
INDEX post_id + lang
```

Do not rely on standard WordPress postmeta uniqueness.

---

# Semantic storage

Prefer custom tables over repeated `LIKE '%keyword%'` queries on postmeta.

## `{$wpdb->prefix}mavo_image_semantics`

One logical row per attachment/language.

Suggested fields:

```text
id
attachment_id
lang
alt_text
alt_hash
indexed_at
source_updated_at
schema_version
```

Suggested indexes:

```text
PRIMARY KEY
UNIQUE attachment_id + lang
INDEX lang
INDEX attachment_id
INDEX indexed_at
```

## `{$wpdb->prefix}mavo_image_concepts`

One row per concept/image/language/source.

Suggested fields:

```text
id
attachment_id
lang
concept
source
confidence
indexed_at
```

Suggested indexes:

```text
PRIMARY KEY
UNIQUE attachment_id + lang + concept + source
INDEX attachment_id
INDEX concept
INDEX lang
INDEX lang + concept
INDEX concept + confidence
```

## `{$wpdb->prefix}mavo_image_usage`

As described above.

Always use `$wpdb->prefix`; never hard-code `wp_`.

---

# Schema management

Provide:

- activation routine;
- `dbDelta()` where appropriate;
- schema-version option;
- idempotent upgrade/migration routine.

Example option:

```text
mavo_image_index_db_version
```

Never destructively drop existing indexed data on a normal plugin update unless a documented migration explicitly requires it.

---

# Index lifecycle

## Full rebuild

Provide a batch-based full rebuild suitable for 7,000+ images and future growth.

Never run a full rebuild during a normal frontend request.

## Incremental indexing

Reindex affected data when:

- attachment alt data changes;
- multilingual alt registry changes;
- attachment metadata changes;
- image is deleted;
- relevant post content changes;
- featured image changes;
- post status changes;
- post language changes;
- hub assignment changes;
- Polylang relationships change where relevant.

If an external plugin has no suitable hook, expose explicit invalidation/reindex helpers such as:

```php
mavo_image_reindex_attachment( int $attachment_id );
mavo_image_reindex_post_usages( int $post_id );
mavo_image_mark_post_context_stale( int $post_id );
```

---

# Staleness

Store a hash of source alt text, e.g.:

```text
alt_hash
```

Also track:

```text
source_updated_at
indexed_at
```

Admin/CLI should report:

```text
indexed
stale
missing
failed
```

Track a concept-dictionary version/hash too. When matching rules change, affected concept rows must become stale/rebuildable.

Example option:

```text
mavo_image_index_concept_version
```

---

# Geography model

Images usually do not carry precise coordinates.

Therefore every geo result must explicitly communicate **source and precision**.

Never imply GPS-level image precision when location was inherited from a post.

Suggested precision/source ladder:

```text
image_exact
image_manual
post_exact
post_region
geo_hub
country
unknown
```

Example result:

```php
[
    'lat'        => 38.72,
    'lng'        => 20.65,
    'precision'  => 'post_exact',
    'source'     => 'geomashup_post',
    'confidence' => 0.70,
    'post_id'    => 12345,
]
```

Confidence may use `0.0–1.0`.

Keep the values simple and documented in V1.

---

# Multiple geo contexts

The same image may be reused in multiple posts.

Therefore image geography is often **usage-context geography**, not one permanent image location.

`mavo_image_get_geo_context()` should support returning:

- a preferred summary;
- all candidate contexts/usages.

If a caller supplies:

```php
['post_id' => 12345]
```

prefer geo inherited from that specific usage.

---

# Post-derived geography

Inspect the actual site's geo storage, including GeoMashup/custom metadata.

Resolution path should roughly be:

```text
image
→ usage post
→ exact/approx post geo
```

If no post coordinate exists:

```text
image
→ usage post
→ primary geo hub
→ geo hub ancestors
```

---

# Hub context

Existing hub metadata:

```text
_mavo_hub_type = geo | theme
_mavo_primary_geo_hub
_mavo_primary_theme_hub
```

Use geo hubs for geographic context.

Example:

```text
image
→ Lefkada article
→ Lefkada hub
→ Greece
→ Europe
```

Use theme hubs separately for thematic context, e.g.:

```text
image
→ article
→ hiking theme hub
```

Do not treat theme hubs as geography.

The plugin should gracefully work when the hub plugin is unavailable.

---

# Image metadata

Expose at least:

```text
attachment_id
URL
mime type
width
height
aspect ratio
orientation
```

Orientation values:

```text
landscape
portrait
square
```

Define a sensible square tolerance.

Also expose which posts use the image as featured image, for example:

```php
'featured_for' => [123, 456]
```

---

# Concept source and confidence

V1 concept source is primarily:

```text
alt
```

But the schema/API must support future sources:

```text
alt
manual
caption
post_context
theme_hub
image_analysis
```

Example:

```php
[
    'slug'       => 'beach',
    'source'     => 'alt',
    'confidence' => 1.0,
]
```

Do not discard source information internally.

---

# Search API

Implement:

```php
mavo_image_search( array $args = [] ): array;
```

Design for arguments like:

```php
[
    'lang' => 'fr',

    'concepts' => [
        'beach',
        'turquoise_water',
    ],

    'concept_operator' => 'AND', // AND | OR

    'exclude_concepts' => [
        'family',
    ],

    'text' => null,

    'geo_hub' => null,
    'include_geo_descendants' => true,

    'theme_hub' => null,
    'include_theme_descendants' => false,

    'post_ids' => [],

    'orientation' => 'landscape',
    'min_width'   => 960,
    'min_height'  => null,

    'featured' => null, // true | false | null
    'role'     => null,

    'after'  => null,
    'before' => null,

    'limit'  => 20,
    'offset' => 0,

    'orderby' => 'relevance',
    'order'   => 'DESC',
]
```

---

# Raw text search

Support exploratory text search independently of concept search:

```php
mavo_image_search([
    'text' => 'coucher de soleil',
    'lang' => 'fr',
]);
```

Do not expose the underlying SQL implementation as part of the API contract.

---

# Concept AND/OR/exclusion semantics

Example:

```php
mavo_image_search([
    'concepts' => ['beach', 'turquoise_water'],
    'concept_operator' => 'AND',
]);
```

means both concepts must match.

With `OR`, either may match.

Support:

```php
'exclude_concepts' => ['family']
```

---

# Search result structure

Return enough data to avoid N+1 lookups.

Example:

```php
[
    'attachment_id' => 54491,
    'score' => 8.7,

    'matched_concepts' => [
        'beach',
        'turquoise_water',
    ],

    'alt'        => '...',
    'url'        => '...',
    'dimensions' => [...],
    'usage'      => [...],
    'geo'        => [...],
]
```

---

# Relevance scoring

V1 scoring may be simple and deterministic.

Possible inputs:

- required concept matches;
- number of matched concepts;
- phrase/synonym specificity;
- language match;
- requested role match;
- featured-image preference if requested;
- dimensions;
- geo precision/context strength.

Expose a filter:

```php
apply_filters(
    'mavo_image_search_score',
    $score,
    $image,
    $args
);
```

Do not over-engineer scoring in V1.

---

# Best-match API

Implement:

```php
mavo_image_best_match( array $args = [] ): ?array;
```

This is a convenience wrapper over semantic search.

Example:

```php
$image = mavo_image_best_match([
    'post_ids'    => [12345],
    'concepts'    => ['hiking'],
    'orientation' => 'landscape',
    'min_width'   => 960,
]);
```

Use case: choose the best hiking-related image from an article instead of always using the featured image.

Another example:

```php
$image = mavo_image_best_match([
    'geo_hub'  => $madeira_id,
    'concepts' => ['garden'],
]);
```

---

# Random order

If `orderby=random` is supported, do **not** use `ORDER BY RAND()` over large result sets.

Use an efficient strategy such as:

- random offset after indexed prefilter;
- sampling candidate IDs;
- seeded hash;
- deterministic cacheable shuffle.

---

# Date support

Date is secondary in V1.

Expose if cheap:

```text
attachment_date
post_date
source_updated_at
```

Optional filters:

```text
after
before
```

Do not build trip-date inference in V1.

---

# Diversity / similarity

Not required in V1.

Design so future versions can add:

```text
diversify_by=concept
perceptual_hash
```

Potential V1.2 additions:

- pHash;
- near-duplicate suppression;
- visual/semantic similarity;
- context-diverse result sets.

---

# Shortcodes

Keep shortcode scope intentionally small.

Do **not** build a gallery shortcode in V1.

## V1 semantic cross-link shortcode

Implement one thin shortcode for links such as:

> Voir d’autres plages aux eaux turquoise

Suggested syntax:

```text
[mavo_image_more concept="turquoise_water"]
```

Optional attributes:

```text
concept
text
lang
post_id
image_id
class
url
```

Examples:

```text
[mavo_image_more concept="turquoise_water" text="Voir d’autres plages aux eaux turquoise"]
```

```text
[mavo_image_more concept="turquoise_water" text="See more beaches with turquoise water"]
```

```text
[mavo_image_more concept="turquoise_water" text="Weitere Strände mit türkisfarbenem Wasser entdecken"]
```

---

# Shortcode target resolution

Do not create a semantic-explorer page inside this plugin.

Resolve the target URL through, in order:

1. explicit shortcode URL if supplied;
2. plugin option mapping concept → destination;
3. filter hook;
4. configured generic semantic-search destination.

Suggested filter:

```php
$url = apply_filters(
    'mavo_image_more_url',
    '',
    $concept,
    $lang,
    $context
);
```

If no target can be resolved:

- output nothing;
- do not output a dead link;
- do not throw a frontend error.

---

# Shortcode image context

Do not depend on fragile server-side inference of “the image immediately above this shortcode”.

Prefer explicit usage:

```text
[mavo_image_more image_id="54491" concept="turquoise_water"]
```

or concept-only author intent:

```text
[mavo_image_more concept="turquoise_water"]
```

If `image_id` is provided, optionally verify that the concept is indexed for that image.

Expose a filter controlling strictness.

---

# Shortcode language behavior

If `lang` is omitted:

1. use current Polylang language;
2. fallback to `fr`.

If `text` is omitted, use translated generic fallback text:

```text
FR: Voir d’autres images similaires
EN: See more like this
DE: Ähnliche Bilder entdecken
```

Concept-specific default text may be added through config/filter.

Output must be:

- server-rendered;
- crawlable;
- accessible;
- cache-friendly;
- same-language;
- minimally styled;
- no JS dependency.

Use normal same-tab links and no `nofollow`.

Provide classes such as:

```text
mavo-image-more
mavo-image-more--turquoise-water
```

Do not hard-code elaborate inline styles.

---

# Admin UI

Add a page such as:

```text
Tools → Image Index
```

Show:

- total WordPress image attachments;
- indexed alt count;
- count by language;
- stale count;
- failed count;
- usage-row count;
- concept count;
- top concepts by language;
- latest rebuild timestamp;
- DB schema version;
- concept dictionary version.

Actions:

```text
Rebuild all
Rebuild stale
Rebuild attachment
Rebuild usages
Test matcher
```

Mutating actions require capability checks and nonces.

Use `manage_options` or a suitable administrator capability.

---

# Concept matcher test utility

Provide an admin test form:

Input:

```text
language
sample alt text
```

Output:

```text
matched canonical concept
matched phrase/synonym
source
confidence
```

Example input:

```text
fr
Plage de sable aux eaux turquoise sous les falaises
```

Expected concepts might include:

```text
beach
turquoise_water
cliff
```

---

# WP-CLI

Provide a command namespace.

Suggested commands:

```bash
wp mavo-image-index status
```

```bash
wp mavo-image-index rebuild --stale
```

```bash
wp mavo-image-index rebuild --attachment=54491
```

```bash
wp mavo-image-index rebuild --all --batch-size=250
```

```bash
wp mavo-image-index concepts
```

```bash
wp mavo-image-index test --lang=fr --text="Plage de sable aux eaux turquoise"
```

```bash
wp mavo-image-index search --lang=fr --concept=beach --concept=turquoise_water
```

CLI rebuilds must:

- batch work;
- show progress;
- avoid memory growth;
- continue after individual failures;
- report failures at the end.

---

# Admin rebuild behavior

Admin-triggered full rebuilds should avoid one giant synchronous HTTP request.

Prefer:

- AJAX batch continuation;
- WP-Cron/event batches;
- another lightweight built-in queue approach.

Do not introduce a large external job framework unless clearly justified.

---

# Hooks

Expose useful hooks, for example:

```php
do_action(
    'mavo_image_indexed',
    $attachment_id,
    $lang,
    $concepts
);
```

```php
do_action(
    'mavo_image_usage_updated',
    $attachment_id,
    $post_id
);
```

```php
apply_filters(
    'mavo_image_concept_definitions',
    $definitions
);
```

```php
apply_filters(
    'mavo_image_search_args',
    $args
);
```

```php
apply_filters(
    'mavo_image_search_results',
    $results,
    $args
);
```

```php
apply_filters(
    'mavo_image_geo_context',
    $contexts,
    $attachment_id,
    $args
);
```

Keep hook naming consistent.

---

# Caching

Frontend lookup should be cheap.

Possible layers:

- in-request static cache;
- WordPress object-cache API;
- transients for expensive aggregate/search results where useful.

Do not assume Redis/Memcached exists.

Do not require a persistent object cache.

Invalidate relevant caches when:

- image is reindexed;
- usage changes;
- post language changes;
- hub relationship changes;
- image metadata changes;
- concept dictionary changes.

---

# Performance requirements

Do not:

- scan all alt texts on frontend requests;
- scan all post content on frontend requests;
- repeatedly walk all hub ancestors per result;
- run unbounded SQL;
- use `ORDER BY RAND()` on large datasets;
- issue one SQL query per result image.

Use indexed queries and bulk-loading.

Target:

- single-image API lookup: very cheap;
- 10–20 result semantic search: fast;
- no noticeable impact on normal page generation.

---

# Security

Follow WordPress best practices:

- prepared SQL;
- sanitise admin/CLI/API input;
- escape output;
- nonces;
- capability checks;
- no direct file execution;
- no arbitrary untrusted regex/code execution;
- no public API accepting raw SQL fragments.

---

# Coding standards / structure

Follow WordPress coding standards.

Keep indexing, DB, matching, geo resolution, search, admin, CLI, and shortcodes separated.

Suggested structure:

```text
mavo-image-index/
├── mavo-image-index.php
├── includes/
│   ├── class-plugin.php
│   ├── class-db.php
│   ├── class-indexer.php
│   ├── class-alt-provider.php
│   ├── class-concept-registry.php
│   ├── class-concept-matcher.php
│   ├── class-usage-indexer.php
│   ├── class-geo-resolver.php
│   ├── class-search.php
│   ├── class-api.php
│   ├── class-shortcodes.php
│   ├── class-admin.php
│   ├── class-cli.php
│   └── functions-public.php
├── data/
│   └── concepts.php
├── assets/
│   └── admin.css
└── tests/
```

Exact structure may differ, but avoid a monolithic main plugin file.

---

# Graceful degradation

The plugin must still work when:

- Polylang is unavailable;
- hub manager is unavailable;
- GeoMashup is unavailable;
- some attachment metadata is missing;
- one language has no alt text;
- an attachment is reused;
- a post has no geo data.

Fallbacks:

```text
language unavailable → fr
geo unavailable      → unknown
theme hub unavailable → omit
```

Never fatal because an optional integration is absent.

---

# No destructive behavior

The plugin must not:

- overwrite alt texts;
- rewrite post content;
- modify image files;
- delete attachments;
- alter hub metadata;
- alter Polylang relationships.

It is an index/intelligence layer only.

---

# Suggested V1 concept set

Start with concepts informed by the current corpus.

## Landscape

```text
beach
sea
coast
cliff
mountain
lake
island
river
forest
waterfall
```

## Places / built environment

```text
house
facade
old_town
village
street
harbour
garden
tower
castle
church
landmark
```

## Visual characteristics

```text
sunset
panoramic_view
turquoise_water
blue_sky
colourful_houses
white_houses
stone_houses
```

## Activities

```text
hiking
cycling
walking
boating
sailing
swimming
```

## Family

```text
family
children
family_from_behind
```

## Routes / terrain

```text
coastal_path
viewpoint
```

Do not add generic concepts merely because a word is frequent.

---

# Public API examples

## Get concepts

```php
$concepts = mavo_image_get_concepts( 54491, 'fr' );
```

## Find turquoise-water beaches

```php
$images = mavo_image_search([
    'lang' => 'fr',
    'concepts' => [
        'beach',
        'turquoise_water',
    ],
    'concept_operator' => 'AND',
    'limit' => 20,
]);
```

## Find a landscape hiking image within one article

```php
$image = mavo_image_best_match([
    'post_ids'    => [12345],
    'concepts'    => ['hiking'],
    'orientation' => 'landscape',
    'min_width'   => 960,
]);
```

## Find garden images within a geo hub

```php
$images = mavo_image_search([
    'geo_hub' => $madeira_hub_id,
    'include_geo_descendants' => true,
    'concepts' => ['garden'],
    'limit' => 12,
]);
```

---

# Implementation phases

## V1.0 — Core index/API

Implement:

- plugin bootstrap;
- schema/versioning;
- multilingual alt adapter;
- canonical concept registry;
- `fr`/`en`/`de` dictionary;
- robust concept matcher;
- semantic index;
- usage index;
- width/height/aspect/orientation;
- public API;
- basic semantic search;
- best-match helper;
- admin status/tools;
- CLI status/rebuild/test/search;
- stale detection;
- one minimal `[mavo_image_more]` shortcode;
- caching/invalidation;
- tests.

V1.0 must still be useful without geo integration.

## V1.1 — Context enrichment

Add/refine:

- post geo integration;
- GeoMashup integration if active;
- geo hub inheritance;
- theme hub context;
- context-aware search;
- geo precision/confidence;
- reused-image context resolution;
- improved role detection.

## V1.2 — Optional advanced intelligence

Possible future work:

- result diversification;
- pHash/perceptual hashes;
- near-duplicate suppression;
- semantic similarity;
- contextual thumbnail selection;
- richer REST support;
- visual-explorer support.

Do not implement V1.2 unless explicitly requested.

---

# Testing requirements

Add unit/integration/manual tests for at least the following.

## Multilingual matching

French:

```text
Plage de sable aux eaux turquoise
```

English:

```text
Sandy beach with turquoise water
```

German:

```text
Sandstrand mit türkisfarbenem Wasser
```

All should map to the same canonical concepts:

```text
beach
turquoise_water
```

## Phrase mapping

These should all map to `family_from_behind`:

```text
famille de dos
family seen from behind
Familie von hinten
```

They may also map to `family` if dictionary rules say so.

## False positives

Verify short keywords do not match inside unrelated longer words.

## Reused attachment

One attachment used in two posts must expose two usages.

Context-specific geo lookup must prefer the requested post usage.

## No geo

No-location images must return:

```text
precision = unknown
```

without fatal errors.

## Hub-inferred geo

An image used in a post with a primary geo hub but no exact coordinates should return `geo_hub` or equivalent as its source/precision.

## Orientation

Test:

```text
landscape
portrait
square
```

and filtering.

## Featured role

Verify `_thumbnail_id` indexing.

## Content role

Verify classic editor markup containing:

```html
class="wp-image-54491"
```

is detected correctly.

## Best match

Given several images in an article, verify concept + orientation constraints return the strongest candidate.

## Language isolation

A French-context search should not unexpectedly return only-English post usages when same-language filtering is active.

## Shortcode

Test:

- valid configured concept target;
- missing target URL;
- missing concept;
- unsupported language;
- custom link text;
- explicit image ID;
- same-language output;
- escaping.

Missing target must fail quietly.

## Stale indexing

Change alt text, confirm stale state, rebuild, confirm concept update.

## Dictionary changes

Change concept dictionary version/hash and confirm relevant semantic rows become stale/rebuildable.

## Performance

Benchmark:

- one image lookup;
- 20-result concept search;
- AND concept search;
- geo-filtered search;
- concept stats;
- rebuild batch.

Check for N+1 queries.

---

# Acceptance criteria

The plugin is acceptable when:

1. activation safely creates/updates its tables;
2. a full batched rebuild can index 7,000+ images without frontend timeout risk;
3. concept matching works consistently in `fr`, `en`, `de`;
4. canonical concepts are language-neutral and shared across languages;
5. other plugins can register concepts;
6. usage is indexed without relying solely on attachment `post_parent`;
7. featured/content roles are exposed;
8. dimensions/aspect/orientation are exposed;
9. geo context explicitly includes source/precision/confidence;
10. no-geo images still work;
11. frontend semantic search uses indexed data rather than scanning all alt texts;
12. AND/OR/exclusion filters work;
13. `mavo_image_best_match()` works;
14. current-language behavior respects Polylang;
15. `[mavo_image_more]` is server-rendered, crawlable, minimal, same-language, and fails quietly without a target;
16. admin tools expose counts/status/stale diagnostics;
17. CLI supports status/rebuild/test/search;
18. post/attachment changes trigger appropriate reindexing/invalidation;
19. normal frontend page rendering does not scan post content;
20. optional integrations fail gracefully;
21. no existing media/post/hub/Polylang data is destructively modified.

---

# Before implementation

Before writing integration code, inspect the actual site for:

1. existing multilingual image-alt registry/storage;
2. actual Polylang language/translation behavior;
3. actual GeoMashup/post-geolocation storage;
4. existing `mavo-hub-manager` public helper functions, if available;
5. exact image HTML patterns used in classic editor / rendered content;
6. whether classes such as `mavo-img-tag` carry useful information;
7. current PHP and WordPress versions;
8. existing Mavo plugin conventions/autoloading patterns.

Prefer public helper APIs from existing Mavo plugins over duplicating knowledge of their raw metadata.

If an integration is uncertain, isolate it behind an adapter/service.

---

# Final implementation philosophy

Treat `mavo-image-index` as the canonical shared answer to:

> **What is this image about, where does it approximately belong, where is it used, and which image best matches a requested context?**

Future Mavo visual features should be able to depend on this plugin without independently reinterpreting raw alt text, post HTML, hub metadata, or attachment dimensions.


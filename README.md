# Mavo Image Index

The shared answer to *what is this image about, where does it approximately
belong, where is it used, and which image best fits a context?* — so galleries,
maps, For You rows and thumbnail pickers stop re-reading alt text, post HTML,
hub metadata and attachment sizes on their own.

Its own front end is one link shortcode and the results page it leads to. Consumers use the procedural API
in `includes/api.php`, guarded with `function_exists()`.

## For consumers

```php
if ( function_exists( 'mavo_image_best_match' ) ) {
    // The best landscape hiking photo in this article, not just its featured image.
    $image = mavo_image_best_match( [
        'post_ids'    => [ $post_id ],
        'concepts'    => [ 'hiking' ],
        'orientation' => 'landscape',
        'min_width'   => 960,
    ] );
}

// Turquoise-water beaches, used in French posts.
mavo_image_search( [ 'lang' => 'fr', 'concepts' => [ 'beach', 'turquoise_water' ], 'limit' => 20 ] );

// Gardens anywhere under Madeira (a mavo-geotag-plus place, i.e. a post_tag term ID).
mavo_image_search( [ 'place' => $madeira_term_id, 'concepts' => [ 'garden' ], 'limit' => 12 ] );

// Images in a mavo-hubs hub and everything below it.
mavo_image_search( [ 'hub' => $hub_id, 'include_hub_descendants' => true ] );
```

| Function | Returns |
|---|---|
| `mavo_image_get( $id, $args )` | The full image object: url, alt per language, concepts, dimensions/orientation, usages, featured_for, geo, dates |
| `mavo_image_get_alt( $id, $lang )` | Alt text in one language, or `null` (no fallback) |
| `mavo_image_get_concepts( $id, $lang )` | Concepts with labels in `$lang`, source and confidence |
| `mavo_image_has_concept( $id, $slug, $lang )` | `bool`; with `$lang`, only that language's alt counts as evidence |
| `mavo_image_get_usages( $id, $args )` | `[ post_id, lang, role, source, position, post_date ]` per usage |
| `mavo_image_get_geo_context( $id, $args )` | Preferred context + `candidates`; always with `precision`, `source`, `confidence` |
| `mavo_image_search( $args )` | Image objects + `score` + `matched_concepts` |
| `mavo_image_best_match( $args )` | One image object or `null` |
| `mavo_image_concepts( $lang )` | The dictionary: slug ⇒ label, group |
| `mavo_image_concept_counts( $lang )` | slug ⇒ images used in posts of `$lang` |
| `mavo_image_results_url( $concept, $lang )` | The results-page URL for a concept, or `''` |
| `mavo_register_image_concept( $slug, $def )` | Add or extend a concept (on `mavo_image_register_concepts`) |

Every argument is documented on the function in `includes/api.php`.

**Language.** `$lang = null` means Polylang's current language, else `fr`.
Concepts are facts about the picture, so evidence from any language's alt text
counts; language decides labels, which alt text comes back, and — in searches,
by default — that the image is used in a post of that language.

**Geography** is inherited from the posts using an image and always says how
approximate it is:

| precision | confidence | from |
|---|---|---|
| `image_exact` / `image_manual` | 1.0 / 0.9 | reserved for image-level data |
| `post_exact` | 0.7 | the post's Geo Mashup coordinates |
| `place_city` / `place_region` / `place_country` / `place_continent` | 0.5 / 0.4 / 0.25 / 0.1 | the post's most specific mavo-geotag-plus place |
| `unknown` | 0 | nothing |

## Hooks

Actions: `mavo_image_register_concepts`, `mavo_image_indexed( $id, $lang, $concepts )`,
`mavo_image_usage_updated( $id, $post_id )`, `mavo_image_index_cache_bumped( $gen )`.

Filters: `mavo_image_concept_definitions`, `mavo_image_concept_label`,
`mavo_image_matcher_output`, `mavo_image_search_args`, `mavo_image_search_score`,
`mavo_image_search_results`, `mavo_image_geo_context`, `mavo_image_post_geo`,
`mavo_image_usage_extract`, `mavo_image_usage_post_types`, `mavo_image_languages`,
`mavo_image_square_tolerance`, `mavo_image_more_url`, `mavo_image_more_text`,
`mavo_image_more_strict`, `mavo_image_results_slug`, `mavo_image_results_rows`, `mavo_image_results_row_min`, `mavo_image_results_featured_fallback`, `mavo_image_results_popularity`, `mavo_image_tile_eyebrow`, `mavo_image_tile_excerpt`.

## Shortcode and results page

```text
[mavo_image_more concept="turquoise_water" text="Voir d’autres plages aux eaux turquoise"]
```

A plain same-tab link, classes `mavo-image-more mavo-image-more--turquoise-water`.
It leads to the **results page**: choose a page in Tools → Image Index → Results
page (the French one; its Polylang translations serve en/de). Each concept then
has its own URL below it — `/images/eaux-turquoise/`,
`/en/<page>/turquoise-water/` — showing a grid of matching photos, each linking
to its article. The bare page lists every concept with photos.

Overrides, first match wins: `url=""` on the shortcode, the concept's line in
*Link targets*, the `mavo_image_more_url` filter, the generic `*` target. With no
target and no results page in that language, it prints nothing.

The bare page becomes a browse page once Tools → Image Index → *Browse rows*
lists concepts: one scrolling row per concept (the theme's `.mv-shelf`), each
with up to 12 articles shown by their matching photo, most read in the same month
last year first (translations' views counted together), and a “see all” link to the
concept grid, followed by the home page's hero buttons. Each article appears **once on
the whole page**: rows fill in their admin order and skip articles a row above
already shows, so a row whose articles are all taken disappears — reorder the rows
to choose which one keeps a popular article. The concept grids use the same popularity
order and still show up to two photos per article — every article's best photo
first, then the second ones. Each tile's eyebrow is the article's *country, region*.

`[mavo_image_results]` places the grid inside the page's content (otherwise it is
appended); `[mavo_image_results concept="garden"]` makes a page for one concept.

## Operating it

- After activation: Tools → Image Index → **Rebuild all** once (or
  `wp mavo-image-index rebuild --all`). From then on saves keep it current.
- After editing `data/concepts.php`: **Rebuild stale** re-matches every row.
- `wp mavo-image-index status | rebuild | concepts | test | search | vocab` —
  see `wp help mavo-image-index`.
- `wp mavo-image-index vocab --lang=fr` lists the frequent words no concept
  matched: the candidates for new synonyms.

## Tests

`tests/run.sh` — no WordPress or MySQL needed; the plugin's SQL runs against
SQLite. See `claude.md` for the design.

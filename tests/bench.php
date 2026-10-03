<?php
/**
 * Rough timings at the site's scale: 7,000 images, 1,500 posts. Not part of
 * run.sh. SQLite, so absolute numbers only indicate; query counts are exact.
 *
 *   php tests/bench.php
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';
global $wpdb;
$alts = ['Plage de sable aux eaux turquoise sous les falaises','Randonnée sur le sentier côtier','Famille de dos devant le château','Maisons colorées dans la vieille ville','Coucher de soleil sur le port','Jardin botanique et vue panoramique','Village blanc au bord de la mer','Cascade dans la forêt'];
$wpdb->pdo->exec('BEGIN');
for ($i=1;$i<=7000;$i++){ mii_image($i, 800+($i%5)*200, 600+($i%3)*300, ['fr'=>$alts[$i%8].' '.$i, 'en'=>'Beach with turquoise water '.$i]); }
for ($p=100001;$p<=101500;$p++){ $c=''; for($k=0;$k<8;$k++){ $c.='<img class="wp-image-'.(($p*7+$k*13)%7000+1).'">'; } mii_post($p,$c,['lang'=>['fr','en','de'][$p%3],'thumb'=>($p%7000)+1]); }
$wpdb->pdo->exec('COMMIT');
$t=microtime(true); $cur=0; do { $wpdb->pdo->exec('BEGIN'); $s=MII_Rebuild::step('images',$cur,250); $wpdb->pdo->exec('COMMIT'); $cur=$s['cursor']; } while(!$s['done']);
printf("index 7000 images: %.1fs (sqlite, 250/batch)\n", microtime(true)-$t);
$t=microtime(true); $cur=0; do { $wpdb->pdo->exec('BEGIN'); $s=MII_Rebuild::step('usages',$cur,250); $wpdb->pdo->exec('COMMIT'); $cur=$s['cursor']; } while(!$s['done']);
printf("usages 1500 posts: %.1fs\n", microtime(true)-$t);
foreach ([['concepts'=>['beach','turquoise_water']], ['concepts'=>['hiking'],'orientation'=>'landscape','min_width'=>960], ['concepts'=>['beach'],'concept_operator'=>'OR','orderby'=>'random'], ['text'=>'sentier côtier']] as $a){
 MII_Cache::bump(); $q=$wpdb->num_queries; $t=microtime(true); $r=mavo_image_search($a+['lang'=>'fr','limit'=>20]);
 printf("search %s: %d results, %d queries, %.0fms\n", json_encode($a,JSON_UNESCAPED_UNICODE), count($r), $wpdb->num_queries-$q, (microtime(true)-$t)*1000);
}
MII_Cache::bump(); $q=$wpdb->num_queries; $t=microtime(true); mavo_image_get(42); printf("single get: %d queries, %.1fms\n", $wpdb->num_queries-$q, (microtime(true)-$t)*1000);
$t=microtime(true); MII_Status::summary(); printf("status: %.0fms\n",(microtime(true)-$t)*1000);

<?php
/**
 * The adapter to wherever multilingual alt text lives.
 *
 * Today that is mavo-img-srcset's Tools → Image alt text: the default language
 * in WordPress's own _wp_attachment_image_alt, the others in _mavo_alt_{lang}
 * on the same attachment. That plugin owns the key scheme, so it is asked
 * first; the fallback below is the same scheme written down, so the index
 * keeps working when mavo-img-srcset is deactivated — its keys stay on the
 * attachments either way.
 *
 * Nothing outside this class knows a meta key. If alt text ever moves to
 * Polylang's translated attachments, this is the one file that changes.
 */

defined( 'ABSPATH' ) || exit;

class MII_Alt {

	public static function meta_key( string $lang ): string {
		if ( is_callable( [ 'Mavo_Alt_Admin', 'meta_key' ] ) ) {
			return (string) Mavo_Alt_Admin::meta_key( $lang );
		}

		return MII_Lang::default_language() === $lang
			? '_wp_attachment_image_alt'
			: '_mavo_alt_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $lang ) );
	}

	/** @return array<string,string> meta key => language */
	public static function meta_keys(): array {
		$out = [];

		foreach ( MII_Lang::languages() as $lang ) {
			$out[ self::meta_key( $lang ) ] = $lang;
		}

		return $out;
	}

	/** The raw stored value, exactly as hashed for staleness. */
	public static function raw( int $attachment_id, string $lang ): string {
		return (string) get_post_meta( $attachment_id, self::meta_key( $lang ), true );
	}

	/** Alt text in one language, or null when there is none. No fallback. */
	public static function get( int $attachment_id, string $lang ): ?string {
		$alt = trim( self::raw( $attachment_id, $lang ) );

		return '' === $alt ? null : $alt;
	}

	/** @return array<string,string> Every language that has alt text. */
	public static function all( int $attachment_id ): array {
		$out = [];

		foreach ( MII_Lang::languages() as $lang ) {
			$alt = self::get( $attachment_id, $lang );

			if ( null !== $alt ) {
				$out[ $lang ] = $alt;
			}
		}

		return $out;
	}
}

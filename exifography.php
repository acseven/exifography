<?php
/*
Plugin Name: Exifography (ACN)
Plugin URI: https://github.com/acseven/exifography
Description: Displays EXIF data for images uploaded with WordPress (shortcode, auto insert, FooGallery lightbox) and imports GPS, flash, exposure bias and IPTC keywords on upload. Fork of Exifography by kristarella.
Version: 1.4.0
Requires at least: 6.0
Requires PHP: 7.4
Author: kristarella, Adriano Neves
Author URI: https://adrianon.com
License: GPL2+
Text Domain: exifography
Domain Path: /languages
Update URI: false
*/

/*
	Exifography is Copyright 2011 Kristen Symonds
	Fork changes Copyright 2018-2026 Adriano Neves

	Exifography is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	any later version.

	Exifography is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The original wp.org plugin (class "exifography") must not run at the same time.
// Note: do not test class_exists( 'Exifography_ACN' ) here: PHP declares the class below at compile time,
// so it always exists already. A constant guards against this file being loaded twice.
if ( class_exists( 'exifography', false ) || defined( 'EXIFOGRAPHY_ACN_FILE' ) ) {
	add_action( 'admin_notices', function () {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Exifography (ACN) is not running: deactivate the original Exifography plugin first.', 'exifography' ) . '</p></div>';
		}
	} );
	return;
}
define( 'EXIFOGRAPHY_ACN_FILE', __FILE__ );

final class Exifography_ACN {

	const VERSION  = '1.4.0';
	const OPTION   = 'exifography_options'; // shared with the original plugin, so settings carry over
	const PAGE     = 'exifography';
	const SETTINGS = 'plugin_options';

	/** Fields understood by the FooGallery lightbox EXIF panel. */
	const LIGHTBOX_FIELDS = array( 'camera', 'aperture', 'shutter_speed', 'focal_length', 'iso', 'created_timestamp' );

	/** @var Exifography_ACN|null */
	private static $instance = null;

	/** @var array field key => label */
	public $fields = array();

	/** @var array html option key => label */
	public $html_options = array();

	/** @var array|null cached options */
	private $options = null;

	/** @var array per-request cache of formatted EXIF, keyed by "id|fields" */
	private $cache = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ), 1 );
		add_filter( 'wp_read_image_metadata', array( $this, 'add_exif' ), 10, 3 );
		add_shortcode( 'exif', array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'auto_insert' ), 2 );

		// FooGallery lightbox integration (only does anything when enabled in the settings).
		add_filter( 'foogallery_attachment_html_link_attributes', array( $this, 'foogallery_link_attributes' ), 25, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'image_attributes' ), 10, 2 );
		add_filter( 'foogallery_lightbox_data_attributes', array( $this, 'foogallery_lightbox_options' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'admin_page' ) );
			add_action( 'admin_init', array( $this, 'options_init' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
			add_action( 'add_meta_boxes', array( $this, 'add_post_box' ) );
			add_action( 'save_post', array( $this, 'save_postdata' ) );
			add_filter( 'attachment_fields_to_edit', array( $this, 'shortcode_id' ), 10, 2 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );
		}
	}

	public function init() {
		load_plugin_textdomain( 'exifography', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		$this->fields = array(
			'aperture'          => __( 'Aperture', 'exifography' ),
			'credit'            => __( 'Credit', 'exifography' ),
			'camera'            => __( 'Camera', 'exifography' ),
			'caption'           => __( 'Caption', 'exifography' ),
			'created_timestamp' => __( 'Taken', 'exifography' ),
			'copyright'         => __( 'Copyright', 'exifography' ),
			'exposure_bias'     => __( 'Exposure bias', 'exifography' ),
			'flash'             => __( 'Flash fired', 'exifography' ),
			'focal_length'      => __( 'Focal length', 'exifography' ),
			'iso'               => __( 'ISO', 'exifography' ),
			'keywords'          => __( 'Keywords', 'exifography' ),
			'location'          => __( 'Location', 'exifography' ),
			'shutter_speed'     => __( 'Shutter speed', 'exifography' ),
			'title'             => __( 'Title', 'exifography' ),
		);
		$this->html_options = array(
			'before_block' => __( 'Before EXIF block', 'exifography' ),
			'before_item'  => __( 'Before EXIF item', 'exifography' ),
			'before_label' => __( 'Before EXIF label', 'exifography' ),
			'after_label'  => __( 'After EXIF label', 'exifography' ),
			'after_item'   => __( 'After EXIF item', 'exifography' ),
			'after_block'  => __( 'After EXIF block', 'exifography' ),
			'sep'          => __( 'Separator for EXIF label', 'exifography' ),
		);
	}

	// === OPTIONS === //

	public static function defaults() {
		return array(
			'before_block'    => '<ul id="%s" class="exif">',
			'before_item'     => '<li class="%s">',
			'before_label'    => '<span class="exif-label">',
			'after_label'     => '</span><span class="exif-value">',
			'after_item'      => '</span></li>',
			'after_block'     => '</ul>',
			'sep'             => ': ',
			'timestamp'       => 'j F, Y',
			'geo_zoom'        => '2',
			'geo_width'       => '100',
			'geo_height'      => '100',
			'exif_fields'     => array(),
			'lightbox_fields' => self::LIGHTBOX_FIELDS,
		);
	}

	public function get_options() {
		if ( null === $this->options ) {
			$saved         = get_option( self::OPTION );
			$saved         = is_array( $saved ) ? $saved : array();
			$defaults      = self::defaults();
			// Settings saved by Exifography <= 1.3.1 have no label wrappers (the label markup, if any, lives in
			// before_item / sep): keep that output unchanged instead of adding the new label spans.
			if ( $saved && ! array_key_exists( 'before_label', $saved ) && ! array_key_exists( 'after_label', $saved ) ) {
				$defaults['before_label'] = '';
				$defaults['after_label']  = '';
				$defaults['after_item']   = '</li>';
			}
			$this->options = array_merge( $defaults, $saved );
			foreach ( array( 'exif_fields', 'lightbox_fields', 'order' ) as $k ) {
				if ( isset( $this->options[ $k ] ) && ! is_array( $this->options[ $k ] ) ) {
					$this->options[ $k ] = array_filter( array_map( 'trim', explode( ',', (string) $this->options[ $k ] ) ) );
				}
			}
		}
		return $this->options;
	}

	public static function activate() {
		$current = get_option( self::OPTION );
		if ( is_array( $current ) && $current ) {
			return; // existing settings (also those of the original plugin) are kept exactly as they are
		}
		$old = get_option( 'thesography_options' );
		if ( is_array( $old ) && $old ) {
			update_option( self::OPTION, $old );
			delete_option( 'thesography_options' );
			return;
		}
		update_option( self::OPTION, self::defaults() );
	}

	private function field_order() {
		$o     = $this->get_options();
		$order = ! empty( $o['order'] ) ? (array) $o['order'] : array();
		foreach ( array_keys( $this->fields ) as $k ) {
			if ( ! in_array( $k, $order, true ) ) {
				$order[] = $k;
			}
		}
		return array_values( array_intersect( $order, array_keys( $this->fields ) ) );
	}

	// === ADD EXTRA EXIF TO DATABASE ON UPLOAD === //

	public function add_exif( $meta, $file, $source_image_type ) {
		if ( ! is_array( $meta ) ) {
			return $meta;
		}
		if ( is_callable( 'iptcparse' ) ) {
			$info = array();
			@getimagesize( $file, $info );
			if ( ! empty( $info['APP13'] ) ) {
				$iptc = iptcparse( $info['APP13'] );
				if ( ! empty( $iptc['2#025'] ) && is_array( $iptc['2#025'] ) ) {
					$meta['keywords'] = implode( ', ', $iptc['2#025'] );
				}
			}
		}
		$types = apply_filters( 'wp_read_image_metadata_types', array( IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM ) );
		if ( is_callable( 'exif_read_data' ) && in_array( $source_image_type, (array) $types, true ) ) {
			$exif = @exif_read_data( $file );
			if ( is_array( $exif ) ) {
				foreach ( array( 'GPSLatitude' => 'latitude', 'GPSLongitude' => 'longitude' ) as $src => $dst ) {
					if ( ! empty( $exif[ $src ] ) && is_array( $exif[ $src ] ) ) {
						$meta[ $dst ] = $exif[ $src ];
					}
				}
				foreach ( array(
					'GPSLatitudeRef'    => 'latitude_ref',
					'GPSLongitudeRef'   => 'longitude_ref',
					'ExposureBiasValue' => 'exposure_bias',
					'Flash'             => 'flash',
				) as $src => $dst ) {
					if ( isset( $exif[ $src ] ) && '' !== $exif[ $src ] && ! is_array( $exif[ $src ] ) ) {
						$meta[ $dst ] = trim( (string) $exif[ $src ] );
					}
				}
			}
		}
		array_walk( $meta, function ( &$value ) {
			if ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			}
		} );
		return $meta;
	}

	// === FORMATTING (single source for shortcode, auto insert and lightbox) === //

	/** Attachment used when no image ID is given: featured image, else first attached image. */
	public function default_image_id( $post_id = 0 ) {
		$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
		if ( ! $post_id ) {
			return 0;
		}
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			return $thumb;
		}
		$images = get_children( array(
			'post_parent'    => $post_id,
			'post_type'      => 'attachment',
			'numberposts'    => 1,
			'post_mime_type' => 'image',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		return $images ? (int) reset( $images ) : 0;
	}

	public function image_meta( $img_id ) {
		$meta = $img_id ? wp_get_attachment_metadata( (int) $img_id ) : false;
		return ( is_array( $meta ) && ! empty( $meta['image_meta'] ) && is_array( $meta['image_meta'] ) ) ? $meta['image_meta'] : array();
	}

	private static function frac( $v ) {
		if ( is_string( $v ) && false !== strpos( $v, '/' ) ) {
			list( $n, $d ) = array_map( 'floatval', explode( '/', $v, 2 ) );
			return $d ? $n / $d : 0.0;
		}
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	public function format_shutter( $s ) {
		$s = self::frac( $s );
		if ( $s <= 0 ) {
			return '';
		}
		if ( $s < 1 ) {
			$inv = 1 / $s;
			// keep one decimal for the classic non-integer speeds (1/1.3, 1/1.6, 1/2.5)
			$val = ( $inv < 4 && abs( $inv - round( $inv ) ) > 0.05 ) ? number_format( $inv, 1, '.', '' ) : (string) round( $inv );
			return '1/' . $val . 's';
		}
		return rtrim( rtrim( number_format( $s, 1, '.', '' ), '0' ), '.' ) . 's';
	}

	public function format_exposure_bias( $v ) {
		$v = (string) $v;
		if ( '' === $v ) {
			return '';
		}
		$ev = __( 'EV', 'exifography' );
		if ( false !== strpos( $v, '/' ) ) {
			list( $n, $d ) = array_map( 'intval', explode( '/', $v, 2 ) );
			if ( 0 === $n || 0 === $d ) {
				return '';
			}
			$f = $n / $d;
			if ( abs( $f - round( $f ) ) < 0.001 ) {
				return sprintf( '%+d%s', (int) round( $f ), $ev );
			}
			if ( abs( $f ) >= 1 ) {
				return sprintf( '%+.1f%s', $f, $ev );
			}
			return sprintf( '%s%d/%d%s', $f < 0 ? '-' : '+', abs( $n ), abs( $d ), $ev );
		}
		$f = (float) $v;
		return $f ? sprintf( '%+.1f%s', $f, $ev ) : '';
	}

	/** GPS data in several forms, or null when the image has no GPS data. */
	public function geo( $img_id ) {
		$m = $this->image_meta( $img_id );
		if ( empty( $m['latitude'] ) || empty( $m['longitude'] ) || ! is_array( $m['latitude'] ) || ! is_array( $m['longitude'] ) ) {
			return null;
		}
		$dec     = function ( $f ) {
			return self::frac( $f[0] ?? 0 ) + self::frac( $f[1] ?? 0 ) / 60 + self::frac( $f[2] ?? 0 ) / 3600;
		};
		$pretty  = function ( $f ) {
			return self::frac( $f[0] ?? 0 ) . '° ' . self::frac( $f[1] ?? 0 ) . '′ ' . round( self::frac( $f[2] ?? 0 ), 2 ) . '″';
		};
		$lat_ref = strtoupper( (string) ( $m['latitude_ref'] ?? 'N' ) );
		$lng_ref = strtoupper( (string) ( $m['longitude_ref'] ?? 'E' ) );
		$lat     = ( 'S' === $lat_ref ? -1 : 1 ) * $dec( $m['latitude'] );
		$lng     = ( 'W' === $lng_ref ? -1 : 1 ) * $dec( $m['longitude'] );
		$coords  = number_format( $lat, 6, '.', '' ) . ',' . number_format( $lng, 6, '.', '' );
		$o       = $this->get_options();
		$args    = array(
			'zoom'    => (int) $o['geo_zoom'],
			'size'    => (int) $o['geo_width'] . 'x' . (int) $o['geo_height'],
			'maptype' => 'roadmap',
			'markers' => rawurlencode( 'color:blue|' . $coords ),
		);
		if ( ! empty( $o['geo_key'] ) ) {
			$args['key'] = rawurlencode( $o['geo_key'] );
		}
		return array(
			'dec'    => $coords,
			'coords' => $pretty( $m['latitude'] ) . ' ' . $lat_ref . ' ' . $pretty( $m['longitude'] ) . ' ' . $lng_ref,
			'url'    => 'https://www.google.com/maps?q=' . rawurlencode( $coords ),
			'map'    => add_query_arg( $args, 'https://maps.googleapis.com/maps/api/staticmap' ),
		);
	}

	/** Back-compat: display_geo( $imgID, 'dec'|'coords'|'url'|'map'|false ). */
	public function display_geo( $img_id = null, $show = false ) {
		$img_id = $img_id ? (int) $img_id : $this->default_image_id();
		$g      = $this->geo( $img_id );
		if ( ! $g ) {
			return '';
		}
		if ( $show && isset( $g[ $show ] ) ) {
			return $g[ $show ];
		}
		return $this->geo_html( $g );
	}

	private function geo_html( $g ) {
		$o    = $this->get_options();
		$html = esc_html( $g['coords'] );
		if ( ! empty( $o['geo_img'] ) ) {
			$html = sprintf( '<img src="%s" alt="%s" width="%d" height="%d" loading="lazy" style="vertical-align:top" />', esc_url( $g['map'] ), esc_attr( $g['coords'] ), (int) $o['geo_width'], (int) $o['geo_height'] );
		}
		if ( ! empty( $o['geo_link'] ) ) {
			$html = '<a href="' . esc_url( $g['url'] ) . '" target="_blank" rel="noopener">' . $html . '</a>';
		}
		return $html;
	}

	/**
	 * Formatted EXIF for an image.
	 *
	 * @param int   $img_id Attachment ID.
	 * @param array $fields Field keys in the wanted order (empty = all, in the saved order).
	 * @return array key => array( 'label' => string, 'text' => plain value, 'html' => escaped HTML value )
	 */
	public function format( $img_id, $fields = array() ) {
		$img_id = (int) $img_id;
		$fields = $fields ? array_values( array_intersect( (array) $fields, array_keys( $this->fields ) ) ) : $this->field_order();
		$ck     = $img_id . '|' . implode( ',', $fields );
		if ( isset( $this->cache[ $ck ] ) ) {
			return $this->cache[ $ck ];
		}
		$m   = $this->image_meta( $img_id );
		$o   = $this->get_options();
		$out = array();
		foreach ( $fields as $key ) {
			$text = '';
			$html = null;
			switch ( $key ) {
				case 'aperture':
					$a    = self::frac( $m['aperture'] ?? 0 );
					$text = $a > 0 ? 'f/' . rtrim( rtrim( number_format( $a, 1, '.', '' ), '0' ), '.' ) : '';
					break;
				case 'shutter_speed':
					$text = $this->format_shutter( $m['shutter_speed'] ?? 0 );
					break;
				case 'focal_length':
					$f    = self::frac( $m['focal_length'] ?? 0 );
					$text = $f > 0 ? round( $f ) . __( 'mm', 'exifography' ) : '';
					break;
				case 'iso':
					$text = ! empty( $m['iso'] ) && ! is_array( $m['iso'] ) ? (string) $m['iso'] : '';
					break;
				case 'created_timestamp':
					$t    = (int) ( $m['created_timestamp'] ?? 0 );
					$text = $t ? date_i18n( ! empty( $o['timestamp'] ) ? $o['timestamp'] : get_option( 'date_format' ), $t ) : '';
					break;
				case 'exposure_bias':
					$text = $this->format_exposure_bias( $m['exposure_bias'] ?? '' );
					break;
				case 'flash':
					if ( isset( $m['flash'] ) && '' !== $m['flash'] && ! is_array( $m['flash'] ) ) {
						$text = ( (int) $m['flash'] & 1 ) ? __( 'yes', 'exifography' ) : __( 'no', 'exifography' );
					}
					break;
				case 'location':
					$g = $this->geo( $img_id );
					if ( $g ) {
						$text = $g['coords'];
						$html = $this->geo_html( $g );
					}
					break;
				default: // camera, credit, caption, copyright, title, keywords
					$text = isset( $m[ $key ] ) && ! is_array( $m[ $key ] ) ? trim( (string) $m[ $key ] ) : '';
			}
			if ( '' === $text ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => $this->fields[ $key ],
				'text'  => $text,
				'html'  => null === $html ? esc_html( $text ) : $html,
			);
		}
		return $this->cache[ $ck ] = apply_filters( 'exifography_format', $out, $img_id, $fields );
	}

	/**
	 * HTML output.
	 *
	 * @param string|array|null $display Fields: "all", comma list or array. Empty = post meta / default options.
	 * @param int|null          $img_id  Attachment ID. Empty = featured / first attached image of the current post.
	 * @param string            $style   "list" (custom HTML options) or "inline".
	 */
	public function display_exif( $display = null, $img_id = null, $style = 'list' ) {
		$o       = $this->get_options();
		$post_id = (int) get_the_ID();
		$img_id  = $img_id ? (int) $img_id : $this->default_image_id( $post_id );
		if ( ! $img_id ) {
			return '';
		}

		if ( is_string( $display ) && '' !== trim( $display ) ) {
			$fields = 'all' === trim( $display ) ? array_keys( $this->fields ) : array_map( 'trim', explode( ',', $display ) );
		} elseif ( is_array( $display ) && $display ) {
			$fields = $display;
		} else {
			$post_fields = $post_id ? get_post_meta( $post_id, '_use_exif', true ) : '';
			if ( 'none' === $post_fields ) {
				return '';
			}
			$fields = $post_fields ? explode( ',', $post_fields ) : (array) $o['exif_fields'];
		}
		// legacy Thesography field names
		$legacy = array( 'time' => 'created_timestamp', 'copy' => 'copyright', 'focus' => 'focal_length', 'shutter' => 'shutter_speed' );
		$fields = array_map( function ( $f ) use ( $legacy ) {
			return $legacy[ $f ] ?? $f;
		}, $fields );
		// fields always follow the saved display order (drag & drop in the settings), as in the original plugin
		$fields = array_values( array_intersect( $this->field_order(), $fields ) );
		if ( ! $fields ) {
			return '';
		}

		$data = $this->format( $img_id, $fields );
		if ( ! $data ) {
			return '';
		}
		$hide_label = ! empty( $o['item_label'] );

		if ( 'inline' === $style ) {
			$items = array();
			foreach ( $data as $key => $d ) {
				$items[] = '<span class="exif-' . esc_attr( $key ) . '"' . ( $hide_label ? '' : ' title="' . esc_attr( $d['label'] ) . '"' ) . '>' . $d['html'] . '</span>';
			}
			return '<p class="exif exif-inline" id="' . esc_attr( 'wp-image-' . $img_id ) . '">' . implode( ' <span class="exif-sep">·</span> ', $items ) . '</p>';
		}

		// "%s" in the custom HTML wrappers is replaced by the field key (or the image id for the block).
		$tpl   = function ( $name, $key ) use ( $o ) {
			return str_replace( '%s', esc_attr( $key ), stripslashes( (string) ( $o[ $name ] ?? '' ) ) );
		};
		$items = array();
		foreach ( $data as $key => $d ) {
			$items[ $key ] = $tpl( 'before_item', $key )
				. $tpl( 'before_label', $key )
				. ( $hide_label ? '' : esc_html( $d['label'] ) . stripslashes( (string) $o['sep'] ) ) // sep is admin HTML, like the wrappers
				. $tpl( 'after_label', $key )
				. $d['html']
				. $tpl( 'after_item', $key );
		}
		$items = apply_filters( 'exifography_display_exif', $items, $post_id, $img_id );
		return $tpl( 'before_block', 'wp-image-' . $img_id ) . implode( '', (array) $items ) . $tpl( 'after_block', '' );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'show'  => '',
			'id'    => '',
			'style' => 'list',
		), $atts, 'exif' );
		$show = $atts['show'];
		if ( '' === $show ) {
			$post_fields = get_post_meta( (int) get_the_ID(), '_use_exif', true );
			$show        = ( $post_fields && 'none' !== $post_fields ) ? $post_fields : 'all';
		}
		return $this->display_exif( $show, $atts['id'] ? (int) $atts['id'] : null, 'inline' === $atts['style'] ? 'inline' : 'list' );
	}

	public function auto_insert( $content ) {
		$o = $this->get_options();
		if ( empty( $o['auto_insert'] ) || is_page() || is_feed() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		return $content . $this->display_exif();
	}

	// === FOOGALLERY LIGHTBOX === //

	private function lightbox_enabled() {
		$o = $this->get_options();
		return ! empty( $o['lightbox'] ) && ! is_admin();
	}

	/** JSON for the FooGallery lightbox "data-exif" attribute ('' when there is nothing to show). */
	public function lightbox_json( $img_id ) {
		$o      = $this->get_options();
		$fields = array_values( array_intersect( (array) $o['lightbox_fields'], self::LIGHTBOX_FIELDS ) );
		if ( ! $img_id || ! $fields ) {
			return '';
		}
		$exif = array();
		foreach ( $this->format( $img_id, $fields ) as $key => $d ) {
			$exif[ $key ] = $d['text'];
		}
		return $exif ? wp_json_encode( $exif ) : '';
	}

	public function foogallery_link_attributes( $attr, $args, $attachment ) {
		if ( $this->lightbox_enabled() && is_object( $attachment ) && ! empty( $attachment->ID ) ) {
			$json = $this->lightbox_json( (int) $attachment->ID );
			if ( $json ) {
				$attr['data-exif'] = $json;
			}
		}
		return $attr;
	}

	/** data-exif on attachment <img> tags, so loose images joined to a FooGallery lightbox can carry EXIF too. */
	public function image_attributes( $attr, $attachment ) {
		if ( $this->lightbox_enabled() && is_object( $attachment ) && ! empty( $attachment->ID ) ) {
			$json = $this->lightbox_json( (int) $attachment->ID );
			if ( $json ) {
				$attr['data-exif'] = $json;
			}
		}
		return $attr;
	}

	public function foogallery_lightbox_options( $options ) {
		if ( $this->lightbox_enabled() && is_array( $options ) ) {
			$o               = $this->get_options();
			$options['exif'] = ! empty( $o['lightbox_mode'] ) ? $o['lightbox_mode'] : 'auto';
		}
		return $options;
	}

	// === ADMIN === //

	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings' ) . '</a>' );
		return $links;
	}

	public function admin_page() {
		add_options_page( __( 'Exifography Options', 'exifography' ), 'Exifography', 'manage_options', self::PAGE, array( $this, 'options_page' ) );
	}

	public function admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		$base = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'exif_admin_style', $base . 'css/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'exif_admin_js', $base . 'js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), self::VERSION, true );
	}

	public function options_page() {
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Exifography Options', 'exifography' ); ?></h1>
	<p><?php echo wp_kses_post( __( 'For instructions and support please visit the <a target="_blank" href="http://kristarella.blog/exifography">Exifography plugin page</a>.', 'exifography' ) ); ?></p>
	<p><?php echo wp_kses_post( __( 'Shortcode: <code>[exif]</code>, <code>[exif id="123"]</code>, <code>[exif show="camera,aperture,shutter_speed" style="inline"]</code>.', 'exifography' ) ); ?></p>
	<form action="options.php" method="post" class="exifography">
		<?php settings_fields( self::OPTION ); ?>
		<?php do_settings_sections( self::SETTINGS ); ?>
		<?php submit_button(); ?>
	</form>
</div>
		<?php
	}

	public function options_init() {
		register_setting( self::OPTION, self::OPTION, array( 'sanitize_callback' => array( $this, 'options_validate' ) ) );

		add_settings_section( 'default_display', __( 'Default EXIF', 'exifography' ), array( $this, 'section_defaults' ), self::SETTINGS );
		foreach ( $this->field_order() as $key ) {
			add_settings_field( 'exif-field-' . $key, $this->fields[ $key ], array( $this, 'field_default' ), self::SETTINGS, 'default_display', $key );
		}

		add_settings_section( 'auto_display', __( 'Auto insert into post', 'exifography' ), array( $this, 'section_auto' ), self::SETTINGS );
		add_settings_field( 'auto_insert', __( 'Automatically display exif', 'exifography' ), array( $this, 'field_checkbox' ), self::SETTINGS, 'auto_display', 'auto_insert' );

		add_settings_section( 'lightbox_display', __( 'FooGallery lightbox', 'exifography' ), array( $this, 'section_lightbox' ), self::SETTINGS );
		add_settings_field( 'lightbox', __( 'Show EXIF in the FooGallery lightbox', 'exifography' ), array( $this, 'field_checkbox' ), self::SETTINGS, 'lightbox_display', 'lightbox' );
		add_settings_field( 'lightbox_fields', __( 'Lightbox fields', 'exifography' ), array( $this, 'field_lightbox_fields' ), self::SETTINGS, 'lightbox_display' );
		add_settings_field( 'lightbox_mode', __( 'Lightbox EXIF display', 'exifography' ), array( $this, 'field_lightbox_mode' ), self::SETTINGS, 'lightbox_display' );

		add_settings_section( 'custom_html', __( 'Custom HTML', 'exifography' ), array( $this, 'section_html' ), self::SETTINGS );
		foreach ( $this->html_options as $key => $label ) {
			add_settings_field( $key, $label, array( $this, 'field_text' ), self::SETTINGS, 'custom_html', $key );
		}
		add_settings_field( 'timestamp', __( 'Timestamp format', 'exifography' ), array( $this, 'field_text' ), self::SETTINGS, 'custom_html', 'timestamp' );
		add_settings_field( 'item_label', __( 'Turn off item label', 'exifography' ), array( $this, 'field_checkbox' ), self::SETTINGS, 'custom_html', 'item_label' );
		add_settings_field( 'geo_link', __( 'Link GEO EXIF to Google Maps', 'exifography' ), array( $this, 'field_checkbox' ), self::SETTINGS, 'custom_html', 'geo_link' );
		add_settings_field( 'geo_img', __( 'Display map thumbnail instead of location coords', 'exifography' ), array( $this, 'field_checkbox' ), self::SETTINGS, 'custom_html', 'geo_img' );
		add_settings_field( 'geo_zoom', __( 'Map zoom (0 is the widest, 21 is close)', 'exifography' ), array( $this, 'field_text' ), self::SETTINGS, 'custom_html', 'geo_zoom' );
		add_settings_field( 'geo_width', __( 'Map width', 'exifography' ), array( $this, 'field_text' ), self::SETTINGS, 'custom_html', 'geo_width' );
		add_settings_field( 'geo_height', __( 'Map height', 'exifography' ), array( $this, 'field_text' ), self::SETTINGS, 'custom_html', 'geo_height' );
		add_settings_field( 'geo_key', __( 'Google Maps API key', 'exifography' ), array( $this, 'field_text' ), self::SETTINGS, 'custom_html', 'geo_key' );
	}

	public function section_defaults() {
		echo '<p>' . esc_html__( 'Set these to create default display options. In the absence of any other settings, these will be used when EXIF is displayed. You can override these within an individual post, shortcode, or function.', 'exifography' ) . ' <em>' . esc_html__( 'Drag and drop to reorder the fields.', 'exifography' ) . '</em></p>';
	}

	public function section_auto() {
		echo '<p>' . esc_html__( 'Automatically insert the EXIF of the featured (or first attached) image at the end of posts. You can override this by deselecting all the display options when editing a single post.', 'exifography' ) . '</p>';
	}

	public function section_lightbox() {
		echo '<p>' . esc_html__( 'Adds the EXIF of each photo to the FooGallery lightbox (also to loose images that open in a FooGallery lightbox) and turns on its EXIF panel.', 'exifography' ) . '</p>';
	}

	public function section_html() {
		echo '<p>' . esc_html__( 'HTML used for the list style. %s is replaced by the field name (or by the image ID in the block wrapper). IDs and classes can be used for styling.', 'exifography' ) . '</p>';
	}

	public function field_default( $key ) {
		$o = $this->get_options();
		printf( '<input id="exif-field-%1$s" value="%1$s" type="checkbox" name="%2$s[exif_fields][]" %3$s />', esc_attr( $key ), esc_attr( self::OPTION ), checked( in_array( $key, (array) $o['exif_fields'], true ), true, false ) );
	}

	public function field_checkbox( $key ) {
		$o = $this->get_options();
		printf( '<input id="%1$s" type="checkbox" value="1" name="%2$s[%1$s]" %3$s />', esc_attr( $key ), esc_attr( self::OPTION ), checked( ! empty( $o[ $key ] ), true, false ) );
	}

	public function field_text( $key ) {
		$o = $this->get_options();
		printf( '<input type="text" id="%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text code" />', esc_attr( $key ), esc_attr( self::OPTION ), esc_attr( stripslashes( (string) ( $o[ $key ] ?? '' ) ) ) );
		if ( 'geo_key' === $key ) {
			echo ' <a href="https://developers.google.com/maps/documentation/maps-static/usage-and-billing" target="_blank" rel="noopener" title="' . esc_attr__( 'Google Maps Static API usage and billing', 'exifography' ) . '">&#9432;</a>';
		}
	}

	public function field_lightbox_fields() {
		$o = $this->get_options();
		foreach ( self::LIGHTBOX_FIELDS as $key ) {
			printf( '<label style="margin-right:1em"><input type="checkbox" value="%1$s" name="%2$s[lightbox_fields][]" %3$s /> %4$s</label>', esc_attr( $key ), esc_attr( self::OPTION ), checked( in_array( $key, (array) $o['lightbox_fields'], true ), true, false ), esc_html( $this->fields[ $key ] ) );
		}
	}

	public function field_lightbox_mode() {
		$o    = $this->get_options();
		$cur  = ! empty( $o['lightbox_mode'] ) ? $o['lightbox_mode'] : 'auto';
		$opts = array(
			'auto'    => __( 'Auto (full on large screens, compact on small)', 'exifography' ),
			'full'    => __( 'Full', 'exifography' ),
			'partial' => __( 'Partial', 'exifography' ),
			'minimal' => __( 'Minimal (icons)', 'exifography' ),
		);
		echo '<select id="lightbox_mode" name="' . esc_attr( self::OPTION ) . '[lightbox_mode]">';
		foreach ( $opts as $v => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $cur, $v, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public function options_validate( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();
		$keys   = array_keys( $this->fields );

		$output['exif_fields']     = array_values( array_intersect( (array) ( $input['exif_fields'] ?? array() ), $keys ) );
		$output['lightbox_fields'] = array_values( array_intersect( (array) ( $input['lightbox_fields'] ?? array() ), self::LIGHTBOX_FIELDS ) );

		foreach ( array( 'auto_insert', 'item_label', 'geo_link', 'geo_img', 'lightbox' ) as $k ) {
			if ( ! empty( $input[ $k ] ) ) {
				$output[ $k ] = 1;
			}
		}
		foreach ( array( 'geo_zoom', 'geo_width', 'geo_height' ) as $k ) {
			if ( isset( $input[ $k ] ) && preg_match( '/^\d{1,4}$/', trim( (string) $input[ $k ] ) ) ) {
				$output[ $k ] = trim( (string) $input[ $k ] );
			}
		}
		if ( isset( $input['lightbox_mode'] ) && in_array( $input['lightbox_mode'], array( 'auto', 'full', 'partial', 'minimal' ), true ) ) {
			$output['lightbox_mode'] = $input['lightbox_mode'];
		}
		if ( isset( $input['order'] ) ) {
			$order           = is_array( $input['order'] ) ? $input['order'] : explode( ',', (string) $input['order'] );
			$output['order'] = array_values( array_intersect( array_map( 'trim', $order ), $keys ) );
		}
		foreach ( array( 'timestamp', 'geo_key' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$output[ $k ] = sanitize_text_field( $input[ $k ] );
			}
		}
		// Custom HTML wrappers are raw (often unclosed) HTML by design: only users allowed unfiltered HTML may save them.
		$saved = $this->get_options();
		foreach ( array_keys( $this->html_options ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$output[ $k ] = current_user_can( 'unfiltered_html' ) ? wp_unslash( (string) $input[ $k ] ) : ( $saved[ $k ] ?? '' );
			}
		}
		$this->options = null;
		$this->cache   = array();
		return $output;
	}

	// === EDIT POST OPTIONS === //

	public function add_post_box() {
		add_meta_box( 'exifography_add_meta', __( 'Add EXIF to post', 'exifography' ), array( $this, 'edit_post_exif' ), 'post', 'side', 'low' );
	}

	public function edit_post_exif( $post ) {
		wp_nonce_field( plugin_basename( __FILE__ ), 'exifography_noncename' );
		echo '<input type="hidden" name="exif_saved" value="1">';
		$o   = $this->get_options();
		$set = get_post_meta( $post->ID, '_use_exif', true );
		$set = $set ? explode( ',', $set ) : (array) $o['exif_fields'];
		echo '<p>' . esc_html__( 'If there is a photo attached to this post, the following details may be added to the end of the post.', 'exifography' ) . '</p><ul style="padding:0 0.9em;">';
		foreach ( $this->fields as $key => $label ) {
			printf( '<li><label><input value="%1$s" type="checkbox" name="exif_fields[]" %2$s /> %3$s</label></li>', esc_attr( $key ), checked( in_array( $key, $set, true ), true, false ), esc_html( $label ) );
		}
		echo '</ul>';
	}

	public function save_postdata( $post_id ) {
		if ( ! isset( $_POST['exifography_noncename'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['exifography_noncename'] ) ), plugin_basename( __FILE__ ) ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['exif_fields'] ) && is_array( $_POST['exif_fields'] ) ) {
			$fields = array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST['exif_fields'] ) ), array_keys( $this->fields ) ) );
			$value  = $fields ? implode( ',', $fields ) : 'none';
		} elseif ( isset( $_POST['exif_saved'] ) ) {
			$value = 'none';
		} else {
			return;
		}
		update_post_meta( $post_id, '_use_exif', $value );
	}

	public function shortcode_id( $form_fields, $post ) {
		$form_fields['exif_shortcode'] = array(
			'label' => __( 'Exifography shortcode', 'exifography' ),
			'input' => 'html',
			'html'  => '<input type="text" class="text" readonly="readonly" name="exif_shortcode" value="' . esc_attr( '[exif id="' . (int) $post->ID . '"]' ) . '" /><br />',
			'helps' => __( 'Copy and paste into your post to show EXIF for this image', 'exifography' ),
		);
		return $form_fields;
	}
}

register_activation_hook( __FILE__, array( 'Exifography_ACN', 'activate' ) );
Exifography_ACN::instance();

// Template tag: manually insert the EXIF output in a theme.
if ( ! function_exists( 'exifography_display_exif' ) ) {
	function exifography_display_exif( $fields = null, $img_id = null, $style = 'list' ) {
		return Exifography_ACN::instance()->display_exif( $fields, $img_id, $style );
	}
}
// Deprecated.
if ( ! function_exists( 'display_exif' ) ) {
	function display_exif( $fields = null, $img_id = null ) {
		return '<!-- Please replace your instances of "display_exif" with "exifography_display_exif". -->';
	}
}

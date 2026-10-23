<?php
if (!defined('ABSPATH')) exit;

class EVIVE_RevSlider {

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/rev-slides', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_sliders'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/rev-slide/(?P<slider_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_slider'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/opcache-reset', [
            'methods'             => 'POST',
            'callback'            => [$this, 'opcache_reset_handler'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/revslider/update-slide-image', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_slide_image'],
            'permission_callback' => $auth,
        ]);
    }

    // Writes a slide's background image directly to wp_revslider_slides.params (card #30).
    // Confirmed via direct schema inspection against a real live slide on whyatwork.nl (not
    // guessed): a slide's background image lives in params.bg.image (a plain URL string) --
    // NOT inside a "slidebg"-type layer in the layers column, which is what get_sr_slides_for_slider()
    // (the existing read path) incorrectly assumes; that's a separate, pre-existing read bug
    // (board card #75), the two are related but this fixes the write side only.
    // SR has no public write API for SR6 (confirmed live -- no "sliderrevolution" REST namespace
    // exists on this site at all), so this is a direct DB write, same pattern already used by
    // get_sliders/get_slider for reading slider metadata.
    public function update_slide_image( WP_REST_Request $request ) {
        if ( ! $this->revslider_active() ) {
            return new WP_Error( 'revslider_inactive', 'Slider Revolution is not active.', array( 'status' => 404 ) );
        }
        $version = $this->revslider_version();
        if ( $version && version_compare( $version, '7.0', '>=' ) ) {
            return new WP_Error( 'sr7_not_supported', 'SR7 not yet supported.', array( 'status' => 400 ) );
        }

        // Field names (slide_id/attachment_id/url) match what wordpress-mcp's
        // revslider_update_slide_image tool already sends -- that tool-facing contract already
        // existed (this route just didn't), so matching it here avoids touching wordpress-mcp too.
        $slide_id  = intval( $request->get_param( 'slide_id' ) );
        $image_url = $request->get_param( 'url' );
        $image_id  = $request->get_param( 'attachment_id' ); // optional WP attachment ID, for real width/height/ratio

        if ( ! $slide_id || ! $image_url ) {
            return new WP_Error( 'missing_params', 'slide_id and url are required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        $slide = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, slider_id, params FROM {$wpdb->prefix}revslider_slides WHERE id = %d", $slide_id
        ), ARRAY_A );
        if ( ! $slide ) {
            return new WP_Error( 'slide_not_found', 'Slide not found.', array( 'status' => 404 ) );
        }

        $params = json_decode( $slide['params'], true );
        if ( ! is_array( $params ) ) $params = array();
        if ( ! isset( $params['bg'] ) || ! is_array( $params['bg'] ) ) $params['bg'] = array();

        $params['bg']['type']  = 'external';
        $params['bg']['image'] = $image_url;
        if ( $image_id ) {
            $meta = wp_get_attachment_image_src( intval( $image_id ), 'full' );
            $params['bg']['imageLib'] = 'medialibrary';
            if ( $meta ) {
                $params['bg']['imageWidth']  = $meta[1];
                $params['bg']['imageHeight'] = $meta[2];
                $params['bg']['imageRatio']  = $meta[2] ? round( $meta[1] / $meta[2], 4 ) : 1;
            }
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}revslider_slides",
            array( 'params' => wp_json_encode( $params ) ),
            array( 'id' => $slide_id )
        );
        if ( $updated === false ) {
            return new WP_Error( 'update_failed', 'Failed to update slide.', array( 'status' => 500 ) );
        }

        // wp_revslider_static_slides mirrors this same slide data (identical params/layers/settings
        // columns) and looks like SR's own cached/static render fallback -- a raw DB write here
        // doesn't go through SR's normal save path, so that mirror is never told to refresh on its
        // own. Clear this slider's static entries so SR regenerates from the real (now-updated) data
        // rather than serving a stale cached version. Confirm-tested live rather than assumed.
        $wpdb->delete( "{$wpdb->prefix}revslider_static_slides", array( 'slider_id' => $slide['slider_id'] ) );

        // Re-read to verify the write actually persisted, not just that the query didn't error.
        $verify = $wpdb->get_var( $wpdb->prepare(
            "SELECT params FROM {$wpdb->prefix}revslider_slides WHERE id = %d", $slide_id
        ) );
        $verify_params = json_decode( $verify, true );
        $verified = is_array( $verify_params ) && ( $verify_params['bg']['image'] ?? null ) === $image_url;

        return rest_ensure_response( array(
            'success'   => (bool) $verified,
            'slide_id'  => $slide_id,
            'slider_id' => (int) $slide['slider_id'],
            'url'       => $image_url,
            'verified'  => $verified,
        ) );
    }

    private function revslider_active() {
        return class_exists('RevSlider') || class_exists('RevSliderFront') || class_exists('UniteFunctionsRev');
    }

    private function revslider_version() {
        if (defined('RS_REVISION')) return RS_REVISION;
        return get_option('revslider-version') ?: null;
    }

    // sliderrevolution/sliders/{id} does not exist as a REST namespace on any real SR6 site
    // (confirmed live against whyatwork.nl -- 404s silently, always returning empty slide data).
    // Reads directly from wp_revslider_slides instead, same table the write path (#30) already
    // uses. params/layers schema below confirmed against real slide rows, not guessed.
    private function get_sr_slides_for_slider($slider_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, slide_order, params, layers FROM {$wpdb->prefix}revslider_slides WHERE slider_id = %d ORDER BY slide_order",
            $slider_id
        ), ARRAY_A);
        if ($rows === null) return null;
        $metas = [];
        foreach ($rows as $row) {
            $params = json_decode($row['params'], true);
            $layers = json_decode($row['layers'], true);
            $metas[] = [
                'id'          => (int) $row['id'],
                'order'       => (int) $row['slide_order'],
                'bg_image'    => $this->extract_bg_image(is_array($params) ? $params : []),
                'text_layers' => $this->extract_text_layers(is_array($layers) ? $layers : []),
            ];
        }
        return $metas;
    }

    // params.bg.image is a plain URL string in real SR6 data -- no attachment id stored
    // alongside it, so resolve one via core WP rather than assuming SR tracks it.
    private function extract_bg_image($params) {
        $url = $params['bg']['image'] ?? null;
        if (!$url) return null;
        return [
            'url'           => $url,
            'attachment_id' => (int) attachment_url_to_postid($url),
        ];
    }

    // Real layers column: object keyed by (non-contiguous) layer id, each a flat
    // {type, alias, text, ...} -- not the nested {content: {text}} shape the old
    // dead-REST-based code assumed.
    private function extract_text_layers($layers) {
        $result = [];
        foreach ($layers as $layer) {
            if (!is_array($layer)) continue;
            if (($layer['type'] ?? null) === 'text') {
                $result[] = ['alias' => $layer['alias'] ?? '', 'text' => $layer['text'] ?? ''];
            }
        }
        return $result;
    }

    public function get_sliders($request) {
        global $wpdb;
        if (!$this->revslider_active()) return rest_ensure_response(['revslider_active' => false]);
        $version = $this->revslider_version();
        if ($version && version_compare($version, '7.0', '>=')) {
            return rest_ensure_response(['revslider_active' => true, 'version' => $version, 'error' => 'SR7 not yet supported.']);
        }
        $rows = $wpdb->get_results("SELECT id, title, alias FROM {$wpdb->prefix}revslider_sliders ORDER BY id", ARRAY_A);
        $sliders = [];
        foreach ($rows as $row) {
            $metas = $this->get_sr_slides_for_slider($row['id']);
            $sliders[] = ['id' => intval($row['id']), 'title' => $row['title'], 'alias' => $row['alias'], 'slide_count' => $metas !== null ? count($metas) : 0];
        }
        return rest_ensure_response(['revslider_active' => true, 'version' => $version, 'sliders' => $sliders]);
    }

    public function get_slider($request) {
        global $wpdb;
        if (!$this->revslider_active()) return new WP_Error('revslider_inactive', 'Slider Revolution is not active.', ['status' => 404]);
        $slider_id = intval($request['slider_id']);
        $slider = $wpdb->get_row($wpdb->prepare("SELECT id, title, alias FROM {$wpdb->prefix}revslider_sliders WHERE id = %d", $slider_id), ARRAY_A);
        if (!$slider) return new WP_Error('slider_not_found', 'Slider not found.', ['status' => 404]);
        $metas = $this->get_sr_slides_for_slider($slider_id);
        if ($metas === null) return new WP_Error('sr_api_error', 'Could not read slider data.', ['status' => 500]);
        $slides = [];
        foreach ($metas as $meta) {
            $slides[] = ['id' => $meta['id'], 'order' => $meta['order'], 'background_image' => $meta['bg_image'], 'text_layers' => $meta['text_layers']];
        }
        return rest_ensure_response(['id' => intval($slider['id']), 'title' => $slider['title'], 'alias' => $slider['alias'], 'slides' => $slides]);
    }

    public function opcache_reset_handler($request) {
        if (!function_exists('opcache_reset')) return rest_ensure_response(['success' => false, 'message' => 'OPcache not available.']);
        $result = opcache_reset();
        return rest_ensure_response(['success' => $result, 'message' => $result ? 'OPcache cleared.' : 'opcache_reset() returned false.']);
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * FAQPage structured data (card #188). Real investigation confirmed Yoast SEO is active on
 * every real client site and already auto-generates a full JSON-LD @graph on every page --
 * writing a second, separate <script> block would risk real duplicate-schema conflicts.
 * Correct mechanism (confirmed against the real installed Yoast source, not guessed): add a
 * piece into Yoast's OWN graph via its documented wpseo_schema_graph_pieces filter, following
 * the exact same pattern Yoast's own native FAQ piece uses (src/generators/schema/faq.php) --
 * that piece is driven by parsed Gutenberg 'yoast/faq-block' content, which never appears on
 * these Elementor-built sites, so it silently never fires; this class provides the same real
 * capability driven by evive's own postmeta instead.
 *
 * Sync-risk answer (also from the investigation): the schema piece below reads _evive_faq_schema
 * live, every time Yoast regenerates its graph (WP does not cache page HTML at the PHP level;
 * LiteSpeed's own page cache is purged on every content write via evive_purge_post_cache(),
 * same as every other content-write endpoint) -- there is no separate stored/stale copy of the
 * rendered JSON-LD to go out of sync; the source of truth is the same postmeta on every render.
 */
class Evive_Faq_Schema {

    const META_KEY = '_evive_faq_schema';

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/faq-schema/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_faq_schema'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/faq-schema/(?P<post_type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_faq_schema'],
            'permission_callback' => $auth,
        ]);
    }

    private function load_item($request) {
        $id = (int) $request->get_param('id');
        $post_type = $request->get_param('post_type');
        $post = get_post($id);
        if (!$post || $post->post_type !== $post_type) return null;
        return $post;
    }

    public function get_faq_schema(WP_REST_Request $request) {
        if (!$this->load_item($request)) return new WP_Error('not_found', 'Item not found', ['status' => 404]);
        $id = (int) $request->get_param('id');
        $items = get_post_meta($id, self::META_KEY, true);
        if (!is_array($items)) $items = [];
        return rest_ensure_response([
            'items'  => $items,
            'schema' => Evive_Seo_Compat::schema_status(),
        ]);
    }

    public function update_faq_schema(WP_REST_Request $request) {
        $post = $this->load_item($request);
        if (!$post) return new WP_Error('not_found', 'Item not found', ['status' => 404]);
        $id = (int) $request->get_param('id');

        $body  = $request->get_json_params() ?: [];
        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            return new WP_Error('bad_items', 'items must be an array of {question, answer} objects', ['status' => 400]);
        }

        $clean = [];
        foreach ($items as $item) {
            $q = trim((string) ($item['question'] ?? ''));
            $a = trim((string) ($item['answer'] ?? ''));
            if ($q === '' || $a === '') {
                return new WP_Error('bad_item', 'Each item needs a non-empty question and answer', ['status' => 400]);
            }
            $clean[] = ['question' => $q, 'answer' => $a];
        }

        if (empty($clean)) {
            delete_post_meta($id, self::META_KEY);
        } else {
            update_post_meta($id, self::META_KEY, $clean);
        }
        evive_purge_post_cache($id);

        return rest_ensure_response([
            'success' => true,
            'items'   => $clean,
            'schema'  => Evive_Seo_Compat::schema_status(),
        ]);
    }
}

// Yoast schema-graph integration. Defined and hooked ONLY when Yoast SEO is actually active --
// this class extends a real Yoast core class that does not exist otherwise, so even parsing
// this block on a Yoast-less site would fatal if it weren't gated. Deferred to plugins_loaded:
// evive-connector's main file loads alphabetically BEFORE wordpress-seo's, so checking
// class_exists() at top-level require time (confirmed live via direct instrumentation) sees
// neither WPSEO_VERSION nor Yoast's autoloader yet, even though both are real and present by
// the time every plugin has finished loading.
add_action('plugins_loaded', function () {

if (
    class_exists('\Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece')
    && Evive_Seo_Compat::schema_status()['ready']
) {

    class Evive_Faq_Schema_Piece extends \Yoast\WP\SEO\Generators\Schema\Abstract_Schema_Piece {

        // Mirrors Yoast's own native FAQ piece (src/generators/schema/faq.php) exactly --
        // same schema_page_type/main_entity_of_page mutation so this correctly combines into
        // the existing WebPage node (@type becomes ["WebPage","FAQPage"], mainEntity links to
        // the Question pieces below) rather than producing a disconnected, orphan FAQPage type.
        public function is_needed() {
            $post_id = $this->context->post->ID ?? 0;
            if (!$post_id) return false;
            $items = get_post_meta($post_id, Evive_Faq_Schema::META_KEY, true);
            if (!is_array($items) || empty($items)) return false;

            if (!is_array($this->context->schema_page_type)) {
                $this->context->schema_page_type = [$this->context->schema_page_type];
            }
            $this->context->schema_page_type[]  = 'FAQPage';
            $this->context->main_entity_of_page = $this->generate_ids($items);

            return true;
        }

        private function generate_ids($items) {
            $ids = [];
            foreach ($items as $i => $item) {
                $ids[] = ['@id' => $this->context->canonical . '#evive-faq-' . $i];
            }
            return $ids;
        }

        public function generate() {
            $post_id = $this->context->post->ID ?? 0;
            $items = get_post_meta($post_id, Evive_Faq_Schema::META_KEY, true);
            if (!is_array($items)) return [];

            $graph = [];
            foreach ($items as $i => $item) {
                $graph[] = $this->generate_question_block($item, $i);
            }
            return $graph;
        }

        private function generate_question_block($item, $i) {
            $url = $this->context->canonical . '#evive-faq-' . $i;
            $data = [
                '@type'          => 'Question',
                '@id'            => $url,
                'position'       => $i + 1,
                'url'            => $url,
                'name'           => $this->helpers->schema->html->smart_strip_tags($item['question']),
                'answerCount'    => 1,
                'acceptedAnswer' => $this->helpers->schema->language->add_piece_language([
                    '@type' => 'Answer',
                    'text'  => $this->helpers->schema->html->sanitize($item['answer']),
                ]),
            ];
            return $this->helpers->schema->language->add_piece_language($data);
        }
    }

    add_filter('wpseo_schema_graph_pieces', function ($pieces, $context) {
        $pieces[] = new Evive_Faq_Schema_Piece();
        return $pieces;
    }, 10, 2);
}

}, 20);

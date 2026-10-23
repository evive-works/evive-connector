<?php
if (!defined('ABSPATH')) exit;

/**
 * Structural changes to an Elementor tree: duplicate, delete, and reorder whole
 * elements (section/column/container/widget), not just settings on ones that already
 * exist. Spun off from #25's audit (card #87) -- update_widget only ever replaces
 * fields on a known element id; nothing before this could insert, remove, or reorder
 * nodes in the tree itself.
 *
 * Deliberately generic across elType (works the same for a top-level section, a
 * column inside it, or a single widget) rather than section-only -- same real value
 * as #86 widening update_widget beyond widget-only, and the tree-walk needed is
 * identical regardless of which level is targeted.
 */
class Evive_Structure {

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/elementor/duplicate-element', [
            'methods'             => 'POST',
            'callback'            => [$this, 'duplicate_element'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/elementor/delete-element', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_element'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/elementor/move-element', [
            'methods'             => 'POST',
            'callback'            => [$this, 'move_element'],
            'permission_callback' => $auth,
        ]);
    }

    public function duplicate_element(WP_REST_Request $request) {
        [$page_id, $widget_id, $elements, $err] = $this->load_request($request);
        if ($err) return $err;

        $used_ids = [];
        $this->collect_ids($elements, $used_ids);

        $new_id = null;
        if (!$this->duplicate_in_tree($elements, $widget_id, $used_ids, $new_id)) {
            return new WP_Error('widget_not_found', "Element {$widget_id} not found", ['status' => 404]);
        }

        evive_save_elementor_data($page_id, $elements);
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'        => true,
            'new_element_id' => $new_id,
            'widget_map'     => $this->build_widget_map($elements),
        ]);
    }

    public function delete_element(WP_REST_Request $request) {
        [$page_id, $widget_id, $elements, $err] = $this->load_request($request);
        if ($err) return $err;

        if (!$this->delete_in_tree($elements, $widget_id)) {
            return new WP_Error('widget_not_found', "Element {$widget_id} not found", ['status' => 404]);
        }

        evive_save_elementor_data($page_id, $elements);
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'            => true,
            'deleted_element_id' => $widget_id,
            'widget_map'         => $this->build_widget_map($elements),
        ]);
    }

    public function move_element(WP_REST_Request $request) {
        [$page_id, $widget_id, $elements, $err] = $this->load_request($request);
        if ($err) return $err;

        $direction = (string) $request->get_param('direction');
        if (!in_array($direction, ['up', 'down'], true)) {
            return new WP_Error('bad_direction', 'direction must be "up" or "down"', ['status' => 400]);
        }

        $moved = false;
        if (!$this->move_in_tree($elements, $widget_id, $direction, $moved)) {
            return new WP_Error('widget_not_found', "Element {$widget_id} not found", ['status' => 404]);
        }

        evive_save_elementor_data($page_id, $elements);
        evive_purge_post_cache($page_id);

        return rest_ensure_response([
            'success'    => true,
            // false (not an error) when the element was already at that edge of its
            // sibling list -- e.g. asking to move the first section further up.
            'moved'      => $moved,
            'widget_map' => $this->build_widget_map($elements),
        ]);
    }

    /**
     * Shared request bootstrap for all three endpoints: resolve the page, load its
     * Elementor tree, and validate the target element id was provided.
     * @return array{0:int,1:string,2:?array,3:?WP_Error}
     */
    private function load_request(WP_REST_Request $request): array {
        $page_id   = (int) $request->get_param('page_id');
        $widget_id = (string) ($request->get_param('widget_id') ?? '');

        if (!$widget_id) {
            return [0, '', null, new WP_Error('missing_params', 'widget_id is required', ['status' => 400])];
        }

        $post = get_post($page_id);
        if (!$post) {
            return [0, '', null, new WP_Error('not_found', 'Page not found', ['status' => 404])];
        }

        $raw      = get_post_meta($page_id, '_elementor_data', true) ?: '[]';
        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return [0, '', null, new WP_Error('no_elementor_data', 'No Elementor data on this item.', ['status' => 404])];
        }

        return [$page_id, $widget_id, $elements, null];
    }

    private function collect_ids(array $elements, array &$ids): void {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            if (!empty($el['id'])) $ids[$el['id']] = true;
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $this->collect_ids($el['elements'], $ids);
            }
        }
    }

    // Elementor element ids must be unique within a single page's tree (used for CSS
    // class targeting, e.g. .elementor-element-{id}) -- the existing whole-post clone
    // path (class-clone.php) never needed this since two different posts never render
    // into the same DOM, but duplicating a node within the SAME page does.
    private function generate_element_id(array &$used_ids): string {
        do {
            $id = substr(md5(uniqid((string) wp_rand(), true)), 0, 7);
        } while (isset($used_ids[$id]));
        $used_ids[$id] = true;
        return $id;
    }

    private function clone_with_fresh_ids(array $element, array &$used_ids): array {
        $element['id'] = $this->generate_element_id($used_ids);
        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $k => $child) {
                if (is_array($child)) {
                    $element['elements'][$k] = $this->clone_with_fresh_ids($child, $used_ids);
                }
            }
        }
        return $element;
    }

    // Same index-based recursive-tree-walk idiom as replace_widget_fields() in
    // class-elementor.php (array&$elements, for-loop over $elements[$i], recurse into
    // $elements[$i]['elements'] by reference) -- kept consistent rather than
    // introducing a different traversal style for this one file.
    private function duplicate_in_tree(array &$elements, string $target_id, array &$used_ids, ?string &$new_id_out): bool {
        for ($i = 0, $n = count($elements); $i < $n; $i++) {
            if (($elements[$i]['id'] ?? '') === $target_id) {
                $copy = $this->clone_with_fresh_ids($elements[$i], $used_ids);
                $new_id_out = $copy['id'];
                array_splice($elements, $i + 1, 0, [$copy]);
                return true;
            }
            if (!empty($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if ($this->duplicate_in_tree($elements[$i]['elements'], $target_id, $used_ids, $new_id_out)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function delete_in_tree(array &$elements, string $target_id): bool {
        for ($i = 0, $n = count($elements); $i < $n; $i++) {
            if (($elements[$i]['id'] ?? '') === $target_id) {
                array_splice($elements, $i, 1);
                return true;
            }
            if (!empty($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if ($this->delete_in_tree($elements[$i]['elements'], $target_id)) {
                    return true;
                }
            }
        }
        return false;
    }

    // $moved stays false (not "not found") when the target sits at the edge of its
    // sibling list already -- that is a legitimate no-op, not an error.
    private function move_in_tree(array &$elements, string $target_id, string $direction, bool &$moved): bool {
        for ($i = 0, $n = count($elements); $i < $n; $i++) {
            if (($elements[$i]['id'] ?? '') === $target_id) {
                $new_index = $direction === 'up' ? $i - 1 : $i + 1;
                if ($new_index >= 0 && $new_index < $n) {
                    [$elements[$i], $elements[$new_index]] = [$elements[$new_index], $elements[$i]];
                    $moved = true;
                }
                return true;
            }
            if (!empty($elements[$i]['elements']) && is_array($elements[$i]['elements'])) {
                if ($this->move_in_tree($elements[$i]['elements'], $target_id, $direction, $moved)) {
                    return true;
                }
            }
        }
        return false;
    }

    // Lightweight id/type map for the response -- not extract_widgets()'s full
    // text-extraction pass, just enough for evive to see the resulting tree shape and
    // confirm the operation landed where intended.
    private function build_widget_map(array $elements, string $path = ''): array {
        $map = [];
        foreach ($elements as $i => $el) {
            if (!is_array($el)) continue;
            $node_path = $path === '' ? (string) $i : "{$path}.{$i}";
            $map[] = [
                'id'         => $el['id'] ?? '',
                'elType'     => $el['elType'] ?? '',
                'widgetType' => $el['widgetType'] ?? null,
                'path'       => $node_path,
            ];
            if (!empty($el['elements']) && is_array($el['elements'])) {
                $map = array_merge($map, $this->build_widget_map($el['elements'], $node_path));
            }
        }
        return $map;
    }
}

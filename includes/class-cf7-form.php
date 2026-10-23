<?php
if (!defined('ABSPATH')) exit;

/**
 * Contact Form 7 field editing (card #190). Investigation confirmed all 3 real client sites
 * use CF7 (not Elementor's native Form widget, WPForms, or Gravity Forms -- those either had 0
 * real forms created or 0 REST-namespace evidence). On liefdevoltrouwen.nl the live Contact page
 * embeds CF7 via a third-party Elementor bridge widget (opal-contactform7) that carries only a
 * form-id reference and styling -- the real field definitions live entirely inside the CF7 form's
 * own post_content, in CF7's own bracket-tag mini-syntax, a completely separate data model from
 * Elementor's. This class never hand-parses or hand-writes that syntax with a custom regex --
 * every read goes through CF7's own real WPCF7_ContactForm::scan_form_tags() (using CF7's own
 * parser, confirmed against its real source in includes/form-tags-manager.php / form-tag.php),
 * and every write goes through CF7's own set_properties()/save() (confirmed in
 * includes/contact-form.php: save() re-serializes ALL properties -- form/mail/mail_2/messages --
 * from a single flattened post_content blob, so hand-editing raw post_content directly would risk
 * corrupting the mail/messages sections). This class only ever touches the 'form' property.
 *
 * Editing is deliberately scoped to a verified allow-list of simple field basetypes (confirmed
 * live against a real form: has_option('placeholder') + reset($tag->values) is CF7's own real
 * placeholder mechanism -- NOT get_option('placeholder',...,true), which only matches colon
 * syntax and returned false against this site's real (working) bare-option placeholder syntax).
 * Nested/content-bearing tag types (acceptance, quiz, group, recaptcha, file, hidden) are
 * rejected with a clear error rather than guessed at, since their real syntax was not verified.
 */
class Evive_Cf7_Form {

    const SIMPLE_BASETYPES = ['text', 'email', 'tel', 'url', 'number', 'date'];
    const TEXTAREA_BASETYPE = 'textarea';
    const CHOICE_BASETYPES = ['select', 'checkbox', 'radio'];
    const SUBMIT_BASETYPE = 'submit';

    public function register_routes() {
        $auth = function () { return current_user_can('edit_posts'); };

        register_rest_route('evive/v1', '/cf7-forms', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_forms'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/cf7-form/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_fields'],
            'permission_callback' => $auth,
        ]);
        register_rest_route('evive/v1', '/cf7-form/(?P<id>\d+)/field/(?P<name>[a-zA-Z0-9_-]+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_field'],
            'permission_callback' => $auth,
        ]);
    }

    private function cf7_active() {
        return class_exists('WPCF7_ContactForm');
    }

    private function get_form($id) {
        if (!$this->cf7_active()) return null;
        $cf7 = WPCF7_ContactForm::get_instance((int) $id);
        return $cf7 ?: null;
    }

    public function list_forms(WP_REST_Request $request) {
        if (!$this->cf7_active()) {
            return new WP_Error('cf7_missing', 'Contact Form 7 is not active on this site.', ['status' => 400]);
        }
        $posts = get_posts(['post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'post_status' => 'any']);
        $forms = array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title], $posts);
        return rest_ensure_response(['forms' => $forms]);
    }

    /**
     * Derive the placeholder text CF7 will actually render, matching its own real rendering
     * logic (modules/text.php etc.): has_option('placeholder' or 'watermark') means the first
     * value is used as the placeholder (and the field's default value is suppressed) -- NOT
     * a colon-style option.
     */
    private function placeholder_of($tag) {
        if ($tag->has_option('placeholder') || $tag->has_option('watermark')) {
            return (string) reset($tag->values) ?: '';
        }
        return null;
    }

    private function tag_to_field($tag) {
        $field = [
            'name'     => $tag->name,
            'type'     => $tag->basetype,
            'required' => $tag->is_required(),
        ];

        if (in_array($tag->basetype, self::SIMPLE_BASETYPES, true) || $tag->basetype === self::TEXTAREA_BASETYPE) {
            $field['placeholder'] = $this->placeholder_of($tag);
        } elseif (in_array($tag->basetype, self::CHOICE_BASETYPES, true)) {
            $field['choices'] = array_values($tag->labels);
        } elseif ($tag->basetype === self::SUBMIT_BASETYPE) {
            $field['button_text'] = (string) reset($tag->values) ?: '';
        }

        $editable = in_array($tag->basetype, self::SIMPLE_BASETYPES, true)
            || $tag->basetype === self::TEXTAREA_BASETYPE
            || in_array($tag->basetype, self::CHOICE_BASETYPES, true)
            || $tag->basetype === self::SUBMIT_BASETYPE;
        $field['editable'] = $editable;

        return $field;
    }

    public function get_fields(WP_REST_Request $request) {
        $cf7 = $this->get_form($request->get_param('id'));
        if (!$cf7) return new WP_Error('not_found', 'Contact Form 7 form not found (or CF7 not active).', ['status' => 404]);

        $fields = array_map([$this, 'tag_to_field'], $cf7->scan_form_tags());
        return rest_ensure_response(['form_id' => $cf7->id(), 'title' => $cf7->title(), 'fields' => array_values($fields)]);
    }

    /**
     * Reconstructs a canonical CF7 tag string from a WPCF7_FormTag's own real components.
     * Matches the exact grammar confirmed against CF7's own parser (parse_atts() in
     * form-tags-manager.php): [type raw_name option1 option2 "value1" "value2"].
     */
    private function format_tag($type, $raw_name, $options, $values) {
        $parts = [$type];
        if ($raw_name !== '') $parts[] = $raw_name;
        foreach ($options as $opt) $parts[] = $opt;
        $inside = implode(' ', $parts);
        foreach ($values as $v) $inside .= ' "' . str_replace('"', '', $v) . '"';
        return '[' . $inside . ']';
    }

    private function tag_type_string($tag) {
        return $tag->basetype . ($tag->is_required() ? '*' : '');
    }

    public function update_field(WP_REST_Request $request) {
        $cf7 = $this->get_form($request->get_param('id'));
        if (!$cf7) return new WP_Error('not_found', 'Contact Form 7 form not found (or CF7 not active).', ['status' => 404]);

        $name = $request->get_param('name');
        $tags = $cf7->scan_form_tags();
        $tag = null;
        foreach ($tags as $t) if ($t->name === $name) { $tag = $t; break; }
        if (!$tag) return new WP_Error('field_not_found', "No field named '{$name}' on this form.", ['status' => 404]);

        $editable_types = array_merge(self::SIMPLE_BASETYPES, [self::TEXTAREA_BASETYPE], self::CHOICE_BASETYPES, [self::SUBMIT_BASETYPE]);
        if (!in_array($tag->basetype, $editable_types, true)) {
            return new WP_Error('not_editable', "Field type '{$tag->basetype}' is not yet supported for editing (only text/email/tel/url/number/date/textarea/select/checkbox/radio/submit are).", ['status' => 400]);
        }

        $body = $request->get_json_params() ?: [];

        // Reconstruct the field's CURRENT canonical string, to safely locate it in the raw form.
        $old_string = $this->format_tag($this->tag_type_string($tag), $tag->raw_name, $tag->options, $tag->raw_values);

        $new_type    = $this->tag_type_string($tag);
        $new_options = $tag->options;
        $new_values  = $tag->raw_values;

        if (array_key_exists('required', $body) && (in_array($tag->basetype, self::SIMPLE_BASETYPES, true) || $tag->basetype === self::TEXTAREA_BASETYPE)) {
            $new_type = $tag->basetype . ($body['required'] ? '*' : '');
        }

        if (array_key_exists('placeholder', $body) && (in_array($tag->basetype, self::SIMPLE_BASETYPES, true) || $tag->basetype === self::TEXTAREA_BASETYPE)) {
            if (!$tag->has_option('placeholder') && !$tag->has_option('watermark')) {
                $new_options[] = 'placeholder';
            }
            $new_values = [(string) $body['placeholder']];
        }

        if (array_key_exists('choices', $body) && in_array($tag->basetype, self::CHOICE_BASETYPES, true)) {
            if (!is_array($body['choices']) || empty($body['choices'])) {
                return new WP_Error('bad_choices', 'choices must be a non-empty array of strings.', ['status' => 400]);
            }
            $new_values = array_map('strval', $body['choices']);
        }

        if (array_key_exists('button_text', $body) && $tag->basetype === self::SUBMIT_BASETYPE) {
            $new_values = [(string) $body['button_text']];
        }

        $new_string = $this->format_tag($new_type, $tag->raw_name, $new_options, $new_values);

        $form_content = $cf7->prop('form');
        if (substr_count($form_content, $old_string) !== 1) {
            return new WP_Error('reconstruction_mismatch', 'Could not safely locate this field\'s exact current tag text in the form -- refusing to edit rather than risk corrupting the form. This can happen with unusual formatting; the field is unchanged.', ['status' => 409]);
        }

        $new_form_content = str_replace($old_string, $new_string, $form_content);
        $cf7->set_properties(['form' => $new_form_content]);
        $cf7->save();

        // A CF7 form's own post has no real viewable URL of its own, and which real page(s)
        // embed it isn't reliably enumerable here (could be an Elementor bridge widget, a
        // shortcode, a widget area, more than one page) -- same reasoning as #88's kit-settings
        // edit, so this clears site-wide rather than guessing which single page to target.
        evive_purge_all_caches();

        // scan_form_tags() caches on the instance -- re-fetch fresh from the just-saved post so
        // the response reflects what was actually written, not a stale in-memory copy.
        $fresh = WPCF7_ContactForm::get_instance($cf7->id());
        $updated_tag = null;
        foreach ($fresh->scan_form_tags() as $t) if ($t->name === $name) { $updated_tag = $t; break; }

        return rest_ensure_response([
            'success' => true,
            'field'   => $updated_tag ? $this->tag_to_field($updated_tag) : null,
        ]);
    }
}

<?php
/**
 * Where translation maps are read and written. The WordPress implementation is WP_Store;
 * the unit tests use an in-memory store.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

interface Store {

	/** @return array<int,int> The translation map of a post, or [] if it has none. */
	public function get( int $blog_id, int $post_id ): array;

	/** @param array<int,int> $map Full translation map, including the post itself. */
	public function set( int $blog_id, int $post_id, array $map ): void;

	public function delete( int $blog_id, int $post_id ): void;
}

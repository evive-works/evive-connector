<?php
/**
 * Symmetric translation linking. Every post in a translation group carries the same map
 * (blog => post, including itself), so linking or unlinking always rewrites all members.
 *
 * @package evive-connector (language, from evive-language 0.1.1; evive #510)
 */

namespace Evive\Connector\Language;

defined( 'ABSPATH' ) || exit;

final class Linker {

	/** @var Store */
	private $store;

	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * The translation group of a post; always contains the post itself.
	 *
	 * @return array<int,int>
	 */
	public function group( int $blog_id, int $post_id ): array {
		$map = $this->store->get( $blog_id, $post_id );
		if ( ( $map[ $blog_id ] ?? 0 ) !== $post_id ) {
			// Missing or stale (the map belongs to another post): treat the post as unlinked.
			$map = [];
		}
		$map[ $blog_id ] = $post_id;
		ksort( $map );
		return $map;
	}

	/**
	 * Link post A (on blog A) to post B (on blog B). Both groups merge; where they disagree about a
	 * blog, A's side wins and the losing post is detached. Returns the new group.
	 *
	 * @return array<int,int>
	 */
	public function link( int $blog_a, int $post_a, int $blog_b, int $post_b ): array {
		if ( $blog_a === $blog_b ) {
			throw new \InvalidArgumentException( 'A post cannot be linked to a post on the same site.' );
		}
		$group_a = $this->group( $blog_a, $post_a );
		$group_b = $this->group( $blog_b, $post_b );

		$new = $group_b;
		foreach ( $group_a as $blog => $post ) {
			if ( $blog !== $blog_b ) {
				$new[ $blog ] = $post;
			}
		}
		$new[ $blog_a ] = $post_a;
		$new[ $blog_b ] = $post_b;
		ksort( $new );

		$this->detach_dropped( [ $group_a, $group_b ], $new );
		$this->write( $new );
		return $new;
	}

	/**
	 * Remove the translation on $target_blog from the group of a post. Returns the group that is left.
	 *
	 * @return array<int,int>
	 */
	public function unlink( int $blog_id, int $post_id, int $target_blog ): array {
		$group = $this->group( $blog_id, $post_id );
		if ( $target_blog === $blog_id || ! isset( $group[ $target_blog ] ) ) {
			return $group;
		}
		$this->store->delete( $target_blog, $group[ $target_blog ] );
		unset( $group[ $target_blog ] );
		$this->write( $group );
		return $group;
	}

	/**
	 * Take a post out of its group (e.g. when it is deleted). The other members stay linked.
	 *
	 * @return array<int,int> The group the other members are left with.
	 */
	public function remove( int $blog_id, int $post_id ): array {
		$group = $this->group( $blog_id, $post_id );
		$this->store->delete( $blog_id, $post_id );
		unset( $group[ $blog_id ] );
		$this->write( $group );
		return $group;
	}

	/**
	 * Write a group to all its members. A group of one is no link at all: its meta is removed.
	 *
	 * @param array<int,int> $group Translation group.
	 */
	private function write( array $group ): void {
		foreach ( $group as $blog => $post ) {
			if ( count( $group ) < 2 ) {
				$this->store->delete( $blog, $post );
			} else {
				$this->store->set( $blog, $post, $group );
			}
		}
	}

	/**
	 * Posts that were in one of the old groups but lost their place in the new group get their meta removed.
	 */
	private function detach_dropped( array $old_groups, array $new ): void {
		foreach ( $old_groups as $old ) {
			foreach ( $old as $blog => $post ) {
				if ( ( $new[ $blog ] ?? 0 ) !== $post ) {
					$this->store->delete( $blog, $post );
				}
			}
		}
	}
}
